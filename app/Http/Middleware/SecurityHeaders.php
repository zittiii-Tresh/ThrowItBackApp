<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production security headers. Applied globally to every web response.
 *
 * Only HSTS is environment-gated (HSTS over HTTP is a no-op and confuses
 * browsers in dev). Everything else is safe to send in all environments.
 *
 * Why each header:
 *  - X-Content-Type-Options: nosniff
 *      Stops browsers guessing MIME types — defends against the kind of
 *      attack where a captured asset gets reinterpreted as JS.
 *  - X-Frame-Options: SAMEORIGIN
 *      Defence-in-depth against clickjacking. CSP frame-ancestors below
 *      is the modern equivalent; we set both because some browsers honour
 *      one and not the other.
 *  - Referrer-Policy: strict-origin-when-cross-origin
 *      Don't leak full URLs (which can include site IDs) to outbound links.
 *  - Permissions-Policy
 *      Disable browser features we don't use — geolocation, camera, mic,
 *      payment, USB. Stops captured pages from prompting users.
 *  - Strict-Transport-Security (production only)
 *      Forces HTTPS for 1 year on this host + all subdomains.
 *  - Content-Security-Policy
 *      Restricts where scripts/styles/fonts/images may load from. Inline
 *      styles allowed because Filament + Livewire emit them; inline
 *      scripts allowed for the same reason. data: + blob: allowed for
 *      images so dashboard charts and inline SVGs work.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip the archive playback routes — they serve captured third-party
        // HTML which has its own much stricter CSP applied by the controller.
        if ($request->is('archive/snapshot/*') || $request->is('archive/asset/*')) {
            return $response;
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                // Inline scripts/styles are unavoidable with Filament + Livewire.
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.bunny.net",
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "frame-src 'self'",
                "frame-ancestors 'self'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]),
        ];

        // HSTS only in production over HTTPS — pointless and confusing on dev HTTP.
        if (app()->environment('production') && $request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            // Don't override headers a controller has set deliberately.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
