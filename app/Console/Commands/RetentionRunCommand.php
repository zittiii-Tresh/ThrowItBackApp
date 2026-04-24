<?php

namespace App\Console\Commands;

use App\Models\CrawlRun;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly cleanup. For each active site:
 *   1. Resolve its effective retention cutoff
 *      (per-site override, or global default, or "keep forever")
 *   2. Find every CrawlRun on that site older than the cutoff
 *   3. Soft-delete those crawls into the "trash" (deleted_at)
 *
 * Soft-delete only — the actual disk free happens later when the
 * trash retention runs (default 7 days). That gives admins an undo
 * window if a misconfigured retention fires.
 *
 * Usage:
 *   php artisan archive:retention                # actual run
 *   php artisan archive:retention --dry-run      # show what WOULD be deleted
 */
class RetentionRunCommand extends Command
{
    protected $signature   = 'archive:retention
                              {--dry-run : List affected crawls without deleting anything}';

    protected $description = 'Soft-delete crawls older than each site\'s retention cutoff';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $totalSoftDeleted = 0;
        $totalBytesEst    = 0;
        $sitesProcessed   = 0;

        foreach (Site::all() as $site) {
            $cutoff = $site->retentionCutoff();
            if ($cutoff === null) {
                continue; // keep forever
            }

            $candidates = CrawlRun::where('site_id', $site->id)
                ->where('created_at', '<', $cutoff)
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            $sitesProcessed++;
            $bytes = (int) $candidates->sum('storage_bytes');
            $totalSoftDeleted += $candidates->count();
            $totalBytesEst    += $bytes;

            $this->line(sprintf(
                '  %s — %d crawl(s) older than %s (~%s)',
                $site->name,
                $candidates->count(),
                $cutoff->format('Y-m-d'),
                $this->humanBytes($bytes),
            ));

            if (! $dry) {
                // Soft-delete: rows go to trash, recoverable for X days.
                // Actual asset/file deletion happens in TrashPurgeCommand.
                CrawlRun::whereIn('id', $candidates->pluck('id'))->delete();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d crawl(s) across %d site(s) (~%s estimated to be freed when trash purges).',
            $dry ? 'Would soft-delete' : 'Soft-deleted',
            $totalSoftDeleted,
            $sitesProcessed,
            $this->humanBytes($totalBytesEst),
        ));

        if (! $dry) {
            Setting::current()->update(['cleanup_last_run_at' => now()]);
        }

        return self::SUCCESS;
    }

    protected function humanBytes(int $b): string
    {
        if ($b < 1024) return $b . ' B';
        if ($b < 1024 ** 2) return round($b / 1024, 1) . ' KB';
        if ($b < 1024 ** 3) return round($b / 1024 ** 2, 1) . ' MB';
        return round($b / 1024 ** 3, 2) . ' GB';
    }
}
