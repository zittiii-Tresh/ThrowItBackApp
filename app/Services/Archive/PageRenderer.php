<?php

namespace App\Services\Archive;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

/**
 * Wraps Browsershot to render a page in real Chromium and return the
 * post-render DOM. Used by ArchiveCrawlObserver when ARCHIVE_RENDERER
 * is set to 'browsershot' (the default in this build).
 *
 * The rendered DOM is much closer to "what the user actually sees" than
 * the raw HTML the server returns — it includes JS-injected content,
 * computed styles, lazy-loaded image src attributes after they fire,
 * etc. That's the whole accuracy story.
 *
 * Falls back gracefully: if Chromium fails for any reason (missing
 * binary, crash, timeout), returns null and the caller can decide
 * whether to fall back to the raw response body.
 */
class PageRenderer
{
    public function __construct(protected ?array $cfg = null)
    {
        $this->cfg = $cfg ?? config('archive.renderer');
    }

    /**
     * Returns true if we should use Browsershot for this run.
     */
    public function isBrowsershotEnabled(): bool
    {
        return ($this->cfg['mode'] ?? 'static') === 'browsershot';
    }

    /**
     * Render a URL in headless Chromium and return the post-JS DOM.
     * Returns null on failure (caller falls back to static body).
     */
    public function renderHtml(string $url): ?string
    {
        if (! $this->isBrowsershotEnabled()) {
            return null;
        }

        try {
            $shot = Browsershot::url($url)
                ->setNodeBinary($this->cfg['node_binary'])
                ->setNpmBinary($this->cfg['npm_binary'])
                ->windowSize($this->cfg['viewport_width'], $this->cfg['viewport_height'])
                ->timeout($this->cfg['timeout_seconds'])
                ->waitUntilNetworkIdle(false)   // wait for activity to die down, not 0 conns
                ->dismissDialogs()
                ->ignoreHttpsErrors();

            // Brief settle delay after network-idle so late JS finishes painting.
            if (($this->cfg['wait_until_ms'] ?? 0) > 0) {
                $shot->setDelay($this->cfg['wait_until_ms']);
            }

            $html = $shot->bodyHtml();
            return $html === '' ? null : $html;
        } catch (\Throwable $e) {
            Log::warning('Browsershot render failed; falling back to static', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
