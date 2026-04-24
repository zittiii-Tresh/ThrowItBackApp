<?php

/**
 * Tunable knobs for the crawl engine and archive playback. All have safe
 * defaults; override via .env for per-environment tuning. Production should
 * be aggressive (own-hosted SAS sites can take it); shaky dev targets like
 * SPA hosts on free Netlify tiers may need the conservative end of the
 * range to avoid 5xx-from-rate-limiting.
 */
return [

    'crawler' => [

        // Parallel HTTP requests in flight. Spatie/Crawler's default is 10;
        // the previous defensive setting was 3 (after partial-failure pain
        // on Netlify SPA cold starts in commit ca26a60). 10 is fine for
        // SAS-owned hosts; drop to 3-5 if a site starts producing partials.
        'concurrency' => (int) env('ARCHIVE_CRAWL_CONCURRENCY', 10),

        // Milliseconds between requests. 0 = full speed (Spatie's default).
        // The old hardcoded 200ms was legacy throttling — at concurrency 10
        // that's a ~2s pause every 10 requests, which dominates wall time
        // on small sites. Set to >0 only if a target host needs it.
        'delay_ms' => (int) env('ARCHIVE_CRAWL_DELAY_MS', 0),

        // Per-request HTTP timeouts (seconds). Generous for SPA cold-starts.
        'timeout'         => (int) env('ARCHIVE_CRAWL_TIMEOUT', 30),
        'connect_timeout' => (int) env('ARCHIVE_CRAWL_CONNECT_TIMEOUT', 10),

        // Max redirects followed per page fetch.
        'max_redirects' => (int) env('ARCHIVE_CRAWL_MAX_REDIRECTS', 3),

        'user_agent' => env('ARCHIVE_CRAWL_USER_AGENT', 'SiteArchiveBot/1.0 (+internal-tool)'),
    ],

    'renderer' => [

        // 'static'      = capture the raw HTML the server returns (fast, ~1-3s/page,
        //                 misses JS-rendered content)
        // 'browsershot' = render in real Chromium (slow, ~5-15s/page, captures
        //                 everything the user actually sees)
        'mode' => env('ARCHIVE_RENDERER', 'browsershot'),

        // Paths to Node + npm — needed by Browsershot to spawn the
        // Puppeteer subprocess. Defaults to Laragon's bundled Node 22.
        'node_binary' => env('ARCHIVE_NODE_BINARY', 'C:/laragon/bin/nodejs/node-v22/node.exe'),
        'npm_binary'  => env('ARCHIVE_NPM_BINARY',  'C:/laragon/bin/nodejs/node-v22/npm.cmd'),

        // Wait for the rendered page to "settle" before capturing. Lets
        // JavaScript-loaded fonts, lazy images, etc finish.
        'wait_until_ms' => (int) env('ARCHIVE_RENDER_WAIT_MS', 1500),

        // Max seconds Chromium gets per page before we give up. Default
        // generous because cold WP backends can be slow.
        'timeout_seconds' => (int) env('ARCHIVE_RENDER_TIMEOUT', 60),

        // Viewport size Chromium renders at. 1440x900 = standard desktop;
        // pages render at their desktop layout, not mobile.
        'viewport_width'  => (int) env('ARCHIVE_RENDER_VIEWPORT_W', 1440),
        'viewport_height' => (int) env('ARCHIVE_RENDER_VIEWPORT_H', 900),
    ],

    'playback' => [

        // Archived assets are content-addressed by sha1(url) per snapshot —
        // the bytes for a given URL within a given snapshot never change.
        // 1 year + immutable lets the browser skip re-validation entirely.
        'asset_max_age' => (int) env('ARCHIVE_ASSET_MAX_AGE', 31536000),
    ],

];
