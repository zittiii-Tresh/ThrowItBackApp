<?php

namespace App\Services\Archive;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses crawled HTML and:
 *   1. extracts every asset reference (images, CSS, JS, fonts)
 *   2. rewrites each reference to point at the archived copy via a URL like
 *      "/archive/asset/{snapshot_id}/{sha1}" which a controller in Phase 6
 *      serves from the `archive` disk.
 *
 * Why rewrite instead of using the original URLs? When we replay a snapshot
 * from a year ago, the live site may have changed or deleted those assets.
 * Pointing at our archive ensures the snapshot keeps rendering even if the
 * live site is gone.
 *
 * Returns:
 *   [
 *     'html'       => rewritten HTML (string),
 *     'title'      => extracted <title> text (string or null),
 *     'asset_urls' => list of absolute asset URLs found (array),
 *   ]
 *
 * The actual downloading is AssetDownloader's job — this class only identifies
 * and rewrites references.
 */
class HtmlRewriter
{
    /**
     * @param  string  $html     raw HTML from the crawler
     * @param  string  $baseUrl  the URL the HTML was fetched from (for resolving
     *                           relative asset paths)
     * @param  int     $snapshotId  used to build the /archive/asset/... rewrite URL
     * @return array{html: string, title: ?string, asset_urls: array<int,string>}
     */
    public function rewrite(string $html, string $baseUrl, int $snapshotId): array
    {
        if ($html === '') {
            return ['html' => '', 'title' => null, 'asset_urls' => []];
        }

        $doc = new DOMDocument();
        // Suppress libxml warnings on malformed markup — the real web is messy.
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $assetUrls = [];

        // The selectors we rewrite. Each tuple: xpath, attribute name.
        $targets = [
            ['//img[@src]',                                'src'],
            ['//img[@srcset]',                             'srcset'],   // handled specially below
            ['//source[@src]',                             'src'],
            ['//source[@srcset]',                          'srcset'],
            ['//link[@rel="stylesheet"][@href]',           'href'],
            ['//link[contains(@rel,"icon")][@href]',       'href'],
            ['//link[@rel="preload"][@href]',              'href'],
            ['//script[@src]',                             'src'],
            ['//video[@src]',                              'src'],
            ['//audio[@src]',                              'src'],
        ];

        foreach ($targets as [$query, $attr]) {
            /** @var DOMElement $node */
            foreach ($xpath->query($query) as $node) {
                $original = $node->getAttribute($attr);
                if ($original === '' || str_starts_with($original, 'data:')) {
                    continue;
                }

                if ($attr === 'srcset') {
                    // srcset = "url 1x, url2 2x" — rewrite each URL independently.
                    $rewritten = $this->rewriteSrcset($original, $baseUrl, $snapshotId, $assetUrls);
                    $node->setAttribute($attr, $rewritten);
                    continue;
                }

                $abs = $this->absoluteUrl($original, $baseUrl);
                if ($abs === null) {
                    continue;
                }
                $assetUrls[] = $abs;
                $node->setAttribute($attr, $this->archiveUrlFor($snapshotId, $abs));
            }
        }

        // Inline style="background-image: url(...)" / background:url(...). Common in
        // portfolio grids (Colorlib templates etc.) where thumbnails are rendered
        // via CSS rather than <img>. Without this the portfolio images show blank.
        foreach ($xpath->query('//*[@style]') as $node) {
            /** @var DOMElement $node */
            $style = $node->getAttribute('style');
            if (! str_contains(strtolower($style), 'url(')) {
                continue;
            }
            $rewritten = $this->rewriteCssUrls($style, $baseUrl, $snapshotId, $assetUrls);
            $node->setAttribute('style', $rewritten);
        }

        // Extract <title>.
        $titleNode = $xpath->query('//title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : null;

        // Re-serialize. HTML5 doesn't need the XML preamble libxml adds.
        $rewrittenHtml = $doc->saveHTML();
        $rewrittenHtml = preg_replace('/^<\?xml[^>]+\?>\s*/', '', $rewrittenHtml ?: '');

        return [
            'html'       => (string) $rewrittenHtml,
            'title'      => $title ?: null,
            'asset_urls' => array_values(array_unique($assetUrls)),
        ];
    }

    /**
     * Rewrites every url(...) occurrence inside a CSS-ish string (inline style
     * attribute or a full CSS file body). Relative paths are resolved against
     * $baseUrl — for inline styles that's the HTML page URL, for CSS files
     * it's the CSS file's own URL (so ./images/foo resolves to the CSS's dir).
     *
     * Collected absolute URLs are pushed into $urls by reference so the
     * caller can feed them to AssetDownloader.
     */
    public function rewriteCssUrls(string $css, string $baseUrl, int $snapshotId, array &$urls): string
    {
        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function (array $m) use ($baseUrl, $snapshotId, &$urls) {
                $url = trim($m[2]);
                if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                    return $m[0];
                }
                $abs = $this->absoluteUrl($url, $baseUrl);
                if ($abs === null) {
                    return $m[0];
                }
                $urls[] = $abs;
                return 'url(' . $this->archiveUrlFor($snapshotId, $abs) . ')';
            },
            $css,
        ) ?? $css;
    }

    /**
     * Rewrites a srcset attribute, collecting each URL into $urls by reference.
     *   "a.jpg 1x, b.jpg 2x"  →  "/archive/asset/..hash1.. 1x, /archive/asset/..hash2.. 2x"
     */
    protected function rewriteSrcset(string $srcset, string $baseUrl, int $snapshotId, array &$urls): string
    {
        $pieces = [];
        foreach (preg_split('/\s*,\s*/', $srcset) as $piece) {
            $piece = trim($piece);
            if ($piece === '') continue;
            // "url size-descriptor" — the descriptor may be missing.
            [$url, $descriptor] = array_pad(preg_split('/\s+/', $piece, 2), 2, null);
            $abs = $this->absoluteUrl($url, $baseUrl);
            if ($abs === null) {
                $pieces[] = $piece;
                continue;
            }
            $urls[] = $abs;
            $pieces[] = trim($this->archiveUrlFor($snapshotId, $abs) . ' ' . ($descriptor ?? ''));
        }
        return implode(', ', $pieces);
    }

    /**
     * Resolves a (possibly relative) URL against $baseUrl. Returns null for
     * things we shouldn't follow: javascript:, mailto:, blob:, etc.
     */
    protected function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($scheme === 'javascript' || $scheme === 'mailto' ||
            $scheme === 'data' || $scheme === 'blob' || $scheme === 'tel') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $base = parse_url($baseUrl);
        if (! $base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $baseScheme = $base['scheme'];
        $baseHost   = $base['host'];
        $basePath   = $base['path'] ?? '/';

        if (str_starts_with($url, '/')) {
            return "{$baseScheme}://{$baseHost}{$url}";
        }

        // Relative to the base path's directory.
        $dir = rtrim(substr($basePath, 0, strrpos($basePath, '/') + 1), '/');
        return "{$baseScheme}://{$baseHost}{$dir}/{$url}";
    }

    /**
     * The in-archive URL that the snapshot viewer's iframe will request.
     * Served by the ArchiveAssetController in Phase 6 (resolves snapshot+hash
     * to the file on disk and streams it with the original mime type).
     */
    protected function archiveUrlFor(int $snapshotId, string $absoluteUrl): string
    {
        return "/archive/asset/{$snapshotId}/" . sha1($absoluteUrl);
    }
}
