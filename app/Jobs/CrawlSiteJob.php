<?php

namespace App\Jobs;

use App\Enums\CrawlStatus;
use App\Enums\TriggerSource;
use App\Models\CrawlRun;
use App\Models\Site;
use App\Services\Archive\ArchiveCrawlObserver;
use App\Services\Archive\AssetDownloader;
use App\Services\Archive\HtmlRewriter;
use App\Support\Schedule as CrawlSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

/**
 * The main crawl job. Creates a CrawlRun, runs Spatie Crawler against the
 * site with its configured depth/max_pages, and hands each fetched page to
 * ArchiveCrawlObserver for storage + asset download.
 *
 * Dispatched by:
 *   - the SiteResource "Crawl now" action (triggered_by = Manual)
 *   - the Phase 4 scheduler tick (triggered_by = Scheduler)
 *   - the artisan crawl:run command (triggered_by = Manual)
 *
 * On Windows dev, Horizon can't run (pcntl is Linux-only), so we run crawls
 * via `php artisan queue:work` which is Windows-safe. Production Linux
 * deploys use Horizon.
 */
class CrawlSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No auto-retry — a failed crawl is a data point we want to surface. */
    public int $tries = 1;

    /** 15 minutes max per crawl — beyond that, something's wrong. */
    public int $timeout = 900;

    public function __construct(
        public int $siteId,
        public TriggerSource $triggeredBy = TriggerSource::Scheduler,
    ) {}

    public function handle(HtmlRewriter $rewriter, AssetDownloader $downloader): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            Log::warning('CrawlSiteJob dispatched for missing site', ['site_id' => $this->siteId]);
            return;
        }

        $run = CrawlRun::create([
            'site_id'      => $site->id,
            'status'       => CrawlStatus::Running,
            'triggered_by' => $this->triggeredBy,
            'started_at'   => now(),
        ]);

        try {
            $observer = new ArchiveCrawlObserver($run, $rewriter, $downloader);

            Crawler::create([
                    'timeout'         => 15,
                    'connect_timeout' => 5,
                    'allow_redirects' => ['max' => 3],
                    'headers'         => [
                        'User-Agent' => 'SiteArchiveBot/1.0 (+internal-tool)',
                    ],
                ])
                ->setMaximumDepth($site->crawl_depth)
                ->setTotalCrawlLimit($site->max_pages)
                ->ignoreRobots()  // SAS-owned sites — our own content, safe to archive
                // Stay on the site's own host. Without this, the crawler
                // follows outbound anchor links (social, GitHub, etc.) and
                // tries to archive external sites. CrawlInternalUrls matches
                // the exact host of the starting URL.
                ->setCrawlProfile(new CrawlInternalUrls($site->base_url))
                ->setCrawlObserver($observer)
                ->startCrawling($site->base_url);

            // Finalize the run. "partial" if any snapshot came back with a
            // 4xx/5xx; otherwise complete.
            $hasFailures = $run->snapshots()->where('status_code', '>=', 400)->exists();

            $run->update([
                'status'            => $hasFailures ? CrawlStatus::Partial : CrawlStatus::Complete,
                'finished_at'       => now(),
                'assets_downloaded' => $downloader->uniqueDownloadCount(),
                'storage_bytes'     => $downloader->totalBytesWritten(),
            ]);

            // Bump the site's schedule state so the All sites table shows
            // "Last crawl: just now" and the scheduler recomputes next_run_at.
            $site->update([
                'last_crawled_at' => now(),
                'next_run_at'     => CrawlSchedule::nextRunFor($site, CarbonImmutable::now()),
            ]);
        } catch (\Throwable $e) {
            Log::error('Crawl job failed', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);

            $run->update([
                'status'        => CrawlStatus::Failed,
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Laravel calls this if the queue worker kills the job (timeout, exception
     * before handle() finishes). Flag any in-flight run as failed.
     */
    public function failed(?\Throwable $e = null): void
    {
        CrawlRun::where('site_id', $this->siteId)
            ->where('status', CrawlStatus::Running)
            ->orderByDesc('id')
            ->limit(1)
            ->update([
                'status'        => CrawlStatus::Failed,
                'finished_at'   => now(),
                'error_message' => $e?->getMessage() ?? 'Job killed by queue worker',
            ]);
    }
}
