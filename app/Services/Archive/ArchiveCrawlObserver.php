<?php

namespace App\Services\Archive;

use App\Models\CrawlRun;
use App\Models\Snapshot;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

/**
 * Bridges Spatie Crawler's lifecycle callbacks to our archive pipeline.
 *
 * For every page Spatie finishes fetching, we:
 *   1. run the HTML through HtmlRewriter (extract + rewrite asset refs,
 *      pull <title>)
 *   2. write the rewritten HTML to the archive disk
 *   3. insert a Snapshot row
 *   4. download each asset URL via AssetDownloader (dedupes within the run)
 *
 * Spatie Crawler is synchronous per-page (it hands us one at a time), so
 * there's no concurrency concern around the shared AssetDownloader cache.
 */
class ArchiveCrawlObserver extends CrawlObserver
{
    public function __construct(
        protected CrawlRun $run,
        protected HtmlRewriter $rewriter,
        protected AssetDownloader $downloader,
    ) {}

    /**
     * Spatie Crawler calls this when a page is fetched successfully.
     * $url is the absolute URL of the page.
     */
    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        $urlString  = (string) $url;
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        // Only archive HTML pages. Other content types the crawler finds
        // (PDFs, downloaded zips) are out of scope for v1.
        if (! str_contains($contentType, 'text/html')) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $body       = (string) $response->getBody();

        // Create the Snapshot row first — we need its ID to build the
        // /archive/asset/{id}/{hash} URLs the rewriter emits.
        $snapshot = Snapshot::create([
            'crawl_run_id' => $this->run->id,
            'url'          => $urlString,
            'path'         => parse_url($urlString, PHP_URL_PATH) ?: '/',
            'status_code'  => $statusCode,
            'title'        => null,   // filled in below
            'html_path'    => SnapshotStorage::pagePath($this->run, $urlString),
            'asset_count'  => 0,
            'html_bytes'   => 0,
        ]);

        // Rewrite HTML and pull out asset URLs.
        $rewrite = $this->rewriter->rewrite($body, $urlString, $snapshot->id);

        Storage::disk('archive')->put($snapshot->html_path, $rewrite['html']);

        // Download each asset. Dedup within the run via AssetDownloader's cache.
        $assetCount = 0;
        foreach ($rewrite['asset_urls'] as $assetUrl) {
            if ($this->downloader->download($this->run, $snapshot, $assetUrl)) {
                $assetCount++;
            }
        }

        $snapshot->update([
            'title'       => $rewrite['title'],
            'asset_count' => $assetCount,
            'html_bytes'  => strlen($rewrite['html']),
        ]);

        // Keep the CrawlRun counters live so the admin dashboard can watch
        // a running crawl in real time.
        $this->run->increment('pages_crawled');
    }

    /**
     * Spatie Crawler calls this for failed fetches (4xx/5xx/network errors).
     * We still insert a Snapshot row so the "page returned 404 at time of
     * crawl" state the proposal PDF calls out is reproducible.
     */
    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        $urlString = (string) $url;

        // Skip URLs that clearly aren't HTML pages — asset files, git repos,
        // PDFs. Spatie Crawler follows every link it finds including <a
        // href="/file.pdf">, and we only want Snapshot rows for real pages.
        if ($this->isAssetishUrl($urlString)) {
            return;
        }

        $status = $requestException->hasResponse()
            ? $requestException->getResponse()->getStatusCode()
            : 0;

        Snapshot::create([
            'crawl_run_id' => $this->run->id,
            'url'          => $urlString,
            'path'         => parse_url($urlString, PHP_URL_PATH) ?: '/',
            'status_code'  => $status,
            'title'        => null,
            'html_path'    => '',
            'asset_count'  => 0,
            'html_bytes'   => 0,
        ]);

        Log::info('Crawl failed for URL', [
            'run_id' => $this->run->id,
            'url'    => $urlString,
            'status' => $status,
        ]);

        $this->run->increment('pages_crawled');
    }

    /**
     * Returns true for URLs that look like static files rather than HTML
     * pages — we don't want Snapshot rows for .png/.pdf/.zip/etc.
     */
    protected function isAssetishUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        return (bool) preg_match(
            '/\.(png|jpe?g|gif|webp|svg|ico|pdf|zip|tar|gz|rar|7z|git|css|js|mjs|woff2?|ttf|otf|eot|mp[34]|webm|mov|json|xml|txt|csv|docx?|xlsx?|pptx?)$/i',
            $path,
        );
    }

    public function finishedCrawling(): void
    {
        // Nothing to do here — the CrawlSiteJob finalizes the run after
        // Spatie's crawler returns, so it has full context (job-level errors,
        // final timing, etc).
    }
}
