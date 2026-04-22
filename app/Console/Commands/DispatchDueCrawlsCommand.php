<?php

namespace App\Console\Commands;

use App\Enums\CrawlStatus;
use App\Enums\TriggerSource;
use App\Models\CrawlRun;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Run every minute by the Laravel Scheduler (routes/console.php).
 *
 * Finds every active site whose next_run_at has passed and spawns a
 * detached background `crawl:run` process for each. Matches the
 * architecture of the Filament "Crawl now" button so no queue:work
 * worker is ever required — `schedule:run` (called by Windows Task
 * Scheduler / cron) is the only moving piece.
 *
 * Flow per due site:
 *   1. null next_run_at so the next tick (60s later) doesn't re-fire
 *      this site while the crawl is still in flight
 *   2. create a CrawlRun row in Running state immediately, so the UI
 *      reflects "crawl in progress" on the next dashboard poll
 *   3. popen + start /B (Windows) or & (Linux) to spawn a detached
 *      `php artisan crawl:run {siteId} --run-id={runId}` — the child
 *      outlives this command's exit and runs independently
 *
 * If a crawl fails, next_run_at stays null and the site shows
 * "— not scheduled —" in the admin. Admins then investigate and either
 * click "Crawl now" manually or flip the active toggle off.
 */
class DispatchDueCrawlsCommand extends Command
{
    protected $signature   = 'crawl:dispatch-due {--dry-run : show what would be dispatched without actually spawning}';
    protected $description = 'Spawn a detached crawl:run process for every site whose next_run_at has passed';

    public function handle(): int
    {
        $due = Site::dueNow()->get();

        if ($due->isEmpty()) {
            $this->line('[crawl:dispatch-due] no sites due');
            return self::SUCCESS;
        }

        foreach ($due as $site) {
            if ($this->option('dry-run')) {
                $this->info("[crawl:dispatch-due] would dispatch #{$site->id} {$site->name}");
                continue;
            }

            // Clear next_run_at so the next minute's tick won't re-fire this
            // site mid-flight. The crawl job sets a fresh next_run_at when
            // it completes (see CrawlSiteJob::handle).
            $site->update(['next_run_at' => null]);

            // Pre-create the run so the Sites + Crawl History tables see
            // "Running" immediately on their next 2s poll tick.
            $run = CrawlRun::create([
                'site_id'      => $site->id,
                'status'       => CrawlStatus::Running,
                'triggered_by' => TriggerSource::Scheduler,
                'started_at'   => now(),
            ]);

            $this->spawnDetached($site->id, $run->id);

            $this->info("[crawl:dispatch-due] spawned crawl for #{$site->id} {$site->name} (run {$run->id})");
        }

        return self::SUCCESS;
    }

    /**
     * Fire `php artisan crawl:run {siteId} --run-id={runId}` as a fully
     * detached process that outlives this command's exit.
     *
     * Windows: uses `start /B` so the spawned PHP is not a child of the
     *          Task Scheduler's cmd — it keeps running once the minute
     *          tick completes.
     * Linux:   uses `&` for the same effect.
     *
     * Output is redirected to the null device; crawl progress is visible
     * via CrawlRun rows in the DB, no log file needed.
     */
    protected function spawnDetached(int $siteId, int $runId): void
    {
        $phpBin  = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $args    = sprintf('crawl:run %d --run-id=%d', $siteId, $runId);

        $cmd = PHP_OS_FAMILY === 'Windows'
            ? "start /B \"\" $phpBin $artisan $args > NUL 2>&1"
            : "$phpBin $artisan $args > /dev/null 2>&1 &";

        pclose(popen($cmd, 'r'));
    }
}
