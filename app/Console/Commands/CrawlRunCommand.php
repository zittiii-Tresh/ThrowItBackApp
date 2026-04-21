<?php

namespace App\Console\Commands;

use App\Enums\TriggerSource;
use App\Jobs\CrawlSiteJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Run a crawl synchronously for one site — used for manual testing and
 * debugging the crawl engine.
 *
 *   php artisan crawl:run tmdbportfolio.netlify.app
 *   php artisan crawl:run 6
 *
 * Accepts either the site's base_url (partial match ok) or its numeric id.
 * Unlike dispatching async via `->dispatch()`, this runs in the current
 * process so stack traces surface directly in the console.
 */
class CrawlRunCommand extends Command
{
    protected $signature   = 'crawl:run {site : Site ID or base_url substring}';
    protected $description = 'Run a crawl synchronously (bypasses the queue) — for manual testing';

    public function handle(): int
    {
        $key  = (string) $this->argument('site');
        $site = is_numeric($key)
            ? Site::find((int) $key)
            : Site::where('base_url', 'like', "%{$key}%")->first();

        if (! $site) {
            $this->error("No site matches '{$key}'.");
            return self::FAILURE;
        }

        $this->info("Crawling {$site->name} ({$site->base_url})");
        $this->info("  depth={$site->crawl_depth}  max_pages={$site->max_pages}");

        $start = microtime(true);

        // dispatchSync runs through the container so Laravel resolves the
        // HtmlRewriter + AssetDownloader dependencies the job's handle()
        // signature asks for.
        CrawlSiteJob::dispatchSync($site->id, TriggerSource::Manual);

        $elapsed = round(microtime(true) - $start, 2);

        $latest = $site->refresh()->latestCrawlRun;
        if (! $latest) {
            $this->warn('No crawl run was recorded.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Crawl finished in ' . $elapsed . 's');
        $this->table(
            ['Run ID', 'Status', 'Pages', 'Assets', 'Storage', 'Duration'],
            [[
                $latest->id,
                $latest->status->label(),
                $latest->pages_crawled,
                $latest->assets_downloaded,
                number_format($latest->storage_bytes / 1024, 1) . ' KB',
                $latest->durationHuman(),
            ]],
        );

        if ($latest->error_message) {
            $this->error('Error: ' . $latest->error_message);
        }

        return self::SUCCESS;
    }
}
