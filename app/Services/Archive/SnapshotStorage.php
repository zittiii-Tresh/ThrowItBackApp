<?php

namespace App\Services\Archive;

use App\Models\CrawlRun;
use Illuminate\Support\Str;

/**
 * Single source of truth for how we lay out archived content on disk.
 *
 * Directory structure (relative to the `archive` disk root):
 *
 *   {site_id}/
 *     {run_id}/
 *       pages/
 *         {sha1(url)}.html
 *       assets/
 *         {sha1(url)}.{ext}
 *
 * Every consumer (SpatieCrawlObserver, AssetDownloader, HtmlRewriter, viewer)
 * goes through this class so the layout stays consistent.
 */
class SnapshotStorage
{
    /** Base directory for a crawl run: "{site_id}/{run_id}" */
    public static function runBase(CrawlRun $run): string
    {
        return "{$run->site_id}/{$run->id}";
    }

    /** Relative path for a captured page's HTML file. */
    public static function pagePath(CrawlRun $run, string $url): string
    {
        return self::runBase($run) . '/pages/' . self::urlHash($url) . '.html';
    }

    /** Relative path for a downloaded asset, extension inferred from the URL. */
    public static function assetPath(CrawlRun $run, string $url, ?string $mimeType = null): string
    {
        $ext = self::extensionFor($url, $mimeType);
        return self::runBase($run) . '/assets/' . self::urlHash($url) . ($ext ? ".{$ext}" : '');
    }

    /**
     * SHA-1 hash of the URL — used as the on-disk filename. URLs themselves
     * can't be used because of length limits, query-string characters, and
     * OS path restrictions.
     */
    public static function urlHash(string $url): string
    {
        return sha1($url);
    }

    /**
     * Infer a reasonable file extension. Tries the URL path first (most
     * reliable for images/fonts), falls back to the Content-Type if the
     * URL is extensionless (e.g. https://cdn.example.com/abcdef?foo=bar).
     */
    protected static function extensionFor(string $url, ?string $mimeType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (preg_match('/\.([a-z0-9]{1,6})$/i', $path, $m)) {
            return strtolower($m[1]);
        }

        return match (Str::before($mimeType ?? '', ';')) {
            'text/html'                => 'html',
            'text/css'                 => 'css',
            'application/javascript',
            'text/javascript'          => 'js',
            'image/jpeg'               => 'jpg',
            'image/png'                => 'png',
            'image/webp'               => 'webp',
            'image/gif'                => 'gif',
            'image/svg+xml'            => 'svg',
            'font/woff'                => 'woff',
            'font/woff2'               => 'woff2',
            'font/ttf'                 => 'ttf',
            default                    => '',
        };
    }
}
