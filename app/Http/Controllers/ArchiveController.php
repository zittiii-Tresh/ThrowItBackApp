<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Snapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Minimal archive playback endpoints. Phase 6 will wrap these in a proper
 * Livewire viewer (with viewport switcher, page tabs, compare, etc.), but
 * these two routes are enough to verify the crawl captured a site correctly:
 *
 *   GET /archive/snapshot/{snapshot} → serves the rewritten HTML body
 *   GET /archive/asset/{snapshot}/{hash} → serves a captured asset file
 *
 * The rewritten HTML emitted by HtmlRewriter already points at the second
 * route, so loading a snapshot URL directly in a browser "just works" —
 * every img/script/stylesheet loads from the archive disk.
 */
class ArchiveController extends Controller
{
    /**
     * Serve the archived HTML for a snapshot. Returns with
     * Content-Type: text/html so the browser renders it.
     */
    public function snapshot(Snapshot $snapshot): Response
    {
        abort_if($snapshot->html_path === '', 404, 'Snapshot has no HTML (fetch failed).');

        $html = Storage::disk('archive')->get($snapshot->html_path);
        abort_if($html === null, 404, 'Archive file missing on disk.');

        // Rewrite anchor hrefs at view time (not crawl time) so clicking
        // project links like <a href="/work"> navigates to the matching
        // archived snapshot from the SAME crawl run. We can't do this at
        // crawl time because snapshots are created in crawl order — a page
        // linking to /work may be rewritten before /work's snapshot exists.
        $html = $this->rewriteAnchors($html, $snapshot);

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // Strict CSP for captured third-party HTML. The iframe sandbox
            // already prevents same-origin access; this CSP is defense-in-
            // depth — restricts what the page can load, blocks framebusting
            // attempts, blocks plugins, and stops it from re-framing us.
            // Asset URLs we serve start with /archive/asset/... on the same
            // origin, so 'self' covers them.
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob:",
                "img-src 'self' data: blob:",
                "media-src 'self' data: blob:",
                "font-src 'self' data:",
                "frame-ancestors 'self'",  // only THIS app may frame archived snapshots
                "form-action 'none'",      // archived forms can't POST anywhere
                "base-uri 'none'",         // archived <base> tag is ignored
                "object-src 'none'",       // no Flash/Java applets
            ]),
        ]);
    }

    /**
     * Rewrites every <a href> in the HTML:
     *   - internal links whose path matches another snapshot in the same
     *     crawl_run → "/archive/snapshot/{match.id}"
     *   - everything else → neutralized with href="#" so the user can't
     *     accidentally leave the archive by clicking outbound links
     *
     * Regex-based rather than DOMDocument here because we've already parsed
     * once at crawl time — a second DOM parse per request would be wasteful.
     */
    protected function rewriteAnchors(string $html, Snapshot $snapshot): string
    {
        // Build a path → snapshot_id map for this run (single query).
        $siblings = Snapshot::where('crawl_run_id', $snapshot->crawl_run_id)
            ->where('status_code', 200)
            ->where('html_path', '!=', '')
            ->pluck('id', 'path')
            ->all();

        $siteHost = parse_url($snapshot->url, PHP_URL_HOST);

        return preg_replace_callback(
            '/<a\b([^>]*?)\bhref=(["\'])([^"\']+)\2([^>]*)>/i',
            function (array $m) use ($siblings, $siteHost) {
                [$full, $preAttrs, $quote, $href, $postAttrs] = $m;

                // Leave anchor fragments (#section) alone.
                if (str_starts_with($href, '#')) {
                    return $full;
                }

                // Leave non-http protocols (mailto:, tel:) alone.
                $scheme = strtolower(parse_url($href, PHP_URL_SCHEME) ?? '');
                if (in_array($scheme, ['mailto', 'tel', 'javascript'], true)) {
                    return $full;
                }

                // Resolve the href's host + path. For relative URLs, use the
                // snapshot's host as the base.
                $hrefHost = parse_url($href, PHP_URL_HOST);
                $hrefPath = parse_url($href, PHP_URL_PATH) ?: '/';

                // Outbound link (different host) → neutralize so the user
                // can't click out of the archive by accident.
                if ($hrefHost && $hrefHost !== $siteHost) {
                    return "<a{$preAttrs}href={$quote}#{$quote}{$postAttrs} data-archived-href=\"{$href}\" title=\"External link — not captured\">";
                }

                // Internal link — look up a matching snapshot for this run.
                if (isset($siblings[$hrefPath])) {
                    $targetId = $siblings[$hrefPath];
                    return "<a{$preAttrs}href={$quote}/archive/snapshot/{$targetId}{$quote}{$postAttrs}>";
                }

                // Internal path but no matching snapshot — leave alone (might
                // be a relative hash link, a form target, etc).
                return $full;
            },
            $html,
        ) ?? $html;
    }

    /**
     * Serve an archived asset (image, CSS, JS, font) — looked up by
     * snapshot + sha1(original_url). That hash is exactly what
     * HtmlRewriter bakes into the saved HTML, so rewritten refs resolve
     * 1:1 through this endpoint.
     *
     * Caching strategy: assets are content-addressed (the sha1 in the URL
     * IS the cache key — a different bytes-on-disk would produce a
     * different sha1). So we set `immutable` + a 1-year max-age and use a
     * strong ETag so repeat hits short-circuit at If-None-Match without
     * ever opening the file. First load: full 200 + body. Every subsequent
     * load: 304 with no body, or skipped entirely by the browser.
     */
    public function asset(Request $request, Snapshot $snapshot, string $hash): SymfonyResponse
    {
        // ETag is the sha1 in the URL itself — already unique per asset.
        // Quoted per RFC 7232. Browsers send it back in If-None-Match.
        $etag = '"' . $hash . '"';

        $maxAge       = (int) config('archive.playback.asset_max_age', 31536000);
        $cacheControl = "public, max-age={$maxAge}, immutable";

        // Fast path: client already has it. Return 304 without touching
        // the DB or disk. Saves the SHA1() query + Storage::exists() +
        // file streaming on every cached hit.
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag'          => $etag,
                'Cache-Control' => $cacheControl,
            ]);
        }

        // Eager-load the dedup pool entry so the resolver below doesn't
        // do a second query.
        $asset = Asset::with('assetFile')
            ->where('snapshot_id', $snapshot->id)
            ->whereRaw('SHA1(url) = ?', [$hash])
            ->first();

        abort_unless($asset !== null, 404);

        // Resolve through the pool first (post-migration assets), falling
        // back to the legacy per-run storage_path for un-migrated rows.
        $path = $asset->effectiveStoragePath();
        abort_unless($path !== null && $path !== '', 404);

        if (! Storage::disk('archive')->exists($path)) {
            abort(404, 'Asset missing on disk.');
        }

        // Stream the binary so large images/videos don't balloon PHP memory.
        return Storage::disk('archive')->response($path, null, [
            'Content-Type'  => $asset->mime_type ?: 'application/octet-stream',
            'Cache-Control' => $cacheControl,
            'ETag'          => $etag,
        ]);
    }
}
