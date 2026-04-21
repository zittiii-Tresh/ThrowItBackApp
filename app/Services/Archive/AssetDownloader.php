<?php

namespace App\Services\Archive;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\CrawlRun;
use App\Models\Snapshot;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads individual assets (images, CSS, JS, fonts) referenced by a
 * crawled page and persists them to the `archive` disk plus an `assets`
 * row pointing at the stored file.
 *
 * Dedupes within a single crawl run — if two pages both reference the
 * same logo, we only hit the network once and reuse the on-disk file.
 * The DB still gets two rows (one per snapshot) because the Assets panel
 * (User Screen 4) lists assets per page.
 */
class AssetDownloader
{
    /** Downloaded URLs (keyed by SHA-1 of URL) → stored path + size + mime. */
    protected array $cache = [];

    public function __construct(
        protected HtmlRewriter $rewriter,
        protected Client $http = new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 3],
            'headers'         => [
                'User-Agent' => 'SiteArchiveBot/1.0 (+internal-tool)',
            ],
        ]),
    ) {}

    /**
     * Download one asset for a snapshot. Returns the created Asset or null
     * if the download failed (we still write the Asset row with status=0
     * so admins can see what couldn't be captured).
     */
    public function download(CrawlRun $run, Snapshot $snapshot, string $url): ?Asset
    {
        $hash = sha1($url);

        // If another page in this run already downloaded this URL, reuse.
        if (isset($this->cache[$hash])) {
            [$path, $size, $mime, $status] = $this->cache[$hash];
            return $this->recordAsset($snapshot, $url, $path, $size, $mime, $status);
        }

        try {
            $response = $this->http->get($url);
        } catch (GuzzleException $e) {
            Log::warning('Asset download failed', [
                'url'       => $url,
                'exception' => $e->getMessage(),
            ]);
            return $this->recordAsset($snapshot, $url, '', 0, null, 0);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $this->cache[$hash] = ['', 0, null, $status];
            return $this->recordAsset($snapshot, $url, '', 0, null, $status);
        }

        $body = (string) $response->getBody();
        $mime = $response->getHeaderLine('Content-Type') ?: null;
        $path = SnapshotStorage::assetPath($run, $url, $mime);

        // CSS body rewriting: any url(...) refs inside a stylesheet need to
        // be resolved against the CSS file's own URL (not the HTML page),
        // then rewritten to archive URLs. Without this, CSS background-image
        // references to fonts/images 404 when the archived page loads.
        if ($mime && str_contains(strtolower($mime), 'text/css')) {
            $cssUrls = [];
            $body = $this->rewriter->rewriteCssUrls($body, $url, $snapshot->id, $cssUrls);

            // Queue the newly-discovered URLs as further downloads so the
            // rewritten url(...) targets actually exist on disk.
            foreach (array_unique($cssUrls) as $cssUrl) {
                if (! isset($this->cache[sha1($cssUrl)])) {
                    $this->download($run, $snapshot, $cssUrl);
                }
            }
        }

        Storage::disk('archive')->put($path, $body);

        $size = strlen($body);
        $this->cache[$hash] = [$path, $size, $mime, $status];

        return $this->recordAsset($snapshot, $url, $path, $size, $mime, $status);
    }

    protected function recordAsset(
        Snapshot $snapshot,
        string $url,
        string $path,
        int $size,
        ?string $mime,
        int $status,
    ): Asset {
        return Asset::create([
            'snapshot_id'  => $snapshot->id,
            'url'          => $url,
            'type'         => AssetType::fromMimeType($mime)->value,
            'mime_type'    => $mime,
            'size_bytes'   => $size,
            'storage_path' => $path,
            'status_code'  => $status,
        ]);
    }

    /** Total bytes written across the whole cache — for CrawlRun.storage_bytes. */
    public function totalBytesWritten(): int
    {
        return array_sum(array_column($this->cache, 1));
    }

    /** Unique asset URLs actually downloaded (cache hits aren't counted). */
    public function uniqueDownloadCount(): int
    {
        return count(array_filter($this->cache, fn ($c) => $c[0] !== ''));
    }
}
