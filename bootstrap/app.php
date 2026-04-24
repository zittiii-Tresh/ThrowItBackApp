<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Security headers on every web response (CSP, HSTS, X-Frame-Options,
        // X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // Trust forwarded headers from any proxy/CDN (Cloudflare, Forge,
        // Fly.io, etc) so $request->secure() correctly reflects the
        // original scheme. Required for HSTS + secure-cookie detection
        // when terminating TLS at the edge.
        $middleware->trustProxies(at: '*', headers:
            Request::HEADER_X_FORWARDED_FOR  |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO|
            Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->booted(function () {
        // Force every URL Laravel generates to use https:// in production.
        // Without this, mixed-content warnings appear when the app sits
        // behind a TLS-terminating proxy.
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }
    })
    ->create();
