<?php

namespace App\Services\Archive;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\CrawlRun;
use App\Models\Snapshot;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads individual assets (images, CSS, JS, fonts) referenced by a
 * crawled page and persists them to the dedup pool (`asset_files`) plus
 * an `assets` row that links the snapshot to the pooled file.
 *
 * Dedup happens at TWO levels:
 *
 *   1. Within-run cache — if two pages in the same crawl reference the
 *      same logo, we only hit the network once.
 *   2. Cross-run / cross-site pool — even if THIS crawl needs to fetch
 *      the bytes (cache miss), we hash them and check the pool. If the
 *      same bytes already exist (from any past crawl, any site), we
 *      skip the disk write and just record an Asset row pointing at the
 *      existing pool entry.
 *
 * Net effect: identical files get stored exactly ONCE on disk regardless
 * of how many crawls reference them.
 */
class AssetDownloader
{
    /** Within-run cache. URL-sha1 => [AssetFile|null, status_code, mime]. */
    protected array $cache = [];

    /** Total bytes ACTUALLY WRITTEN to the pool by this run (excludes
     *  duplicates that hit the pool's existing entries — those wrote 0 bytes). */
    protected int $bytesWrittenThisRun = 0;

    /** Distinct URLs successfully fetched (cache hits excluded). */
    protected int $networkFetchCount = 0;

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
     * Download one asset for a snapshot. Returns the created Asset row, or
     * still creates an Asset row with status_code=0 + null asset_file for
     * downloads that failed (so admins can see what couldn't be captured).
     */
    public function download(CrawlRun $run, Snapshot $snapshot, string $url): ?Asset
    {
        $urlKey = sha1($url);

        // Within-run cache hit: another page in this run already resolved
        // this URL. Reuse the AssetFile pointer (no network, no hash).
        if (isset($this->cache[$urlKey])) {
            [$assetFile, $status, $mime] = $this->cache[$urlKey];
            return $this->recordAsset($snapshot, $url, $assetFile, $status, $mime);
        }

        try {
            $response = $this->http->get($url);
        } catch (GuzzleException $e) {
            Log::warning('Asset download failed', [
                'url'       => $url,
                'exception' => $e->getMessage(),
            ]);
            $this->cache[$urlKey] = [null, 0, null];
            return $this->recordAsset($snapshot, $url, null, 0, null);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $this->cache[$urlKey] = [null, $status, null];
            return $this->recordAsset($snapshot, $url, null, $status, null);
        }

        $body = (string) $response->getBody();
        $mime = $response->getHeaderLine('Content-Type') ?: null;

        // CSS body rewriting: any url(...) refs inside a stylesheet need to
        // be resolved against the CSS file's own URL (not the HTML page),
        // then rewritten to archive URLs.
        if ($mime && str_contains(strtolower($mime), 'text/css')) {
            $cssUrls = [];
            $body = $this->rewriter->rewriteCssUrls($body, $url, $snapshot->id, $cssUrls);

            // Queue the newly-discovered URLs as further downloads so the
            // rewritten url(...) targets actually exist in the pool.
            foreach (array_unique($cssUrls) as $cssUrl) {
                if (! isset($this->cache[sha1($cssUrl)])) {
                    $this->download($run, $snapshot, $cssUrl);
                }
            }
        }

        // -------- DEDUP POOL CHECK --------
        // sha256 of the BYTES is the cross-crawl/cross-site dedup key.
        // If these exact bytes already exist in the pool (from any past
        // run of any site), firstOrCreatePool reuses that entry — no disk
        // write happens. If they're new, the bytes get written exactly
        // once at the pool path.
        $sha256 = hash('sha256', $body);
        $existingBefore = AssetFile::where('sha256', $sha256)->exists();

        $assetFile = AssetFile::firstOrCreatePool(
            sha256: $sha256,
            bytes:  $body,
            mime:   $mime,
            extension: pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: null,
        );

        if (! $existingBefore) {
            // We just added a brand-new file to the pool — count its bytes.
            $this->bytesWrittenThisRun += strlen($body);
        }
        $this->networkFetchCount++;

        $this->cache[$urlKey] = [$assetFile, $status, $mime];

        return $this->recordAsset($snapshot, $url, $assetFile, $status, $mime);
    }

    /**
     * Create the per-snapshot Asset row that links to the pool file.
     * Bumps the pool's ref_count so it knows another snapshot needs it.
     * For failed downloads (assetFile === null), still creates a row so
     * admins can see what couldn't be captured.
     */
    protected function recordAsset(
        Snapshot $snapshot,
        string $url,
        ?AssetFile $assetFile,
        int $status,
        ?string $mime,
    ): Asset {
        $asset = Asset::create([
            'snapshot_id'   => $snapshot->id,
            'asset_file_id' => $assetFile?->id,
            'url'           => $url,
            // Pass URL so AssetType falls back to file extension when the
            // mime type is empty ("") or generic ("application/octet-stream"),
            // which happens with Netlify- and CDN-served fonts.
            'type'          => AssetType::fromMimeType($mime, $url)->value,
            'mime_type'     => $mime,
            'size_bytes'    => $assetFile?->size_bytes ?? 0,
            // Legacy column kept null for new pool-backed rows.
            'storage_path'  => '',
            'status_code'   => $status,
        ]);

        if ($assetFile) {
            $assetFile->addRef();
        }

        return $asset;
    }

    /**
     * Bytes physically written to the pool by THIS run. Excludes bytes
     * for URLs that hit existing pool entries (true dedup wins).
     * Used by CrawlRun.storage_bytes to reflect actual disk impact.
     */
    public function totalBytesWritten(): int
    {
        return $this->bytesWrittenThisRun;
    }

    /** Distinct URLs successfully fetched over the network this run. */
    public function uniqueDownloadCount(): int
    {
        return $this->networkFetchCount;
    }
}
