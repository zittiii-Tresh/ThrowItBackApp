<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configurePasswordDefaults();
    }

    /**
     * Named rate limiters for sensitive endpoints. Throttles per IP so a
     * single attacker can't grind through credentials, but multiple users
     * behind a NAT don't lock each other out of normal usage.
     */
    protected function configureRateLimiters(): void
    {
        // Filament admin login — applied via the panel provider's
        // ->authMiddleware('throttle:filament-login') hook.
        RateLimiter::for('filament-login', fn (Request $request) =>
            Limit::perMinute(5)->by($request->ip())
        );

        // Email verification clicks — 6 per minute per IP. Already wired
        // on the verification.verify route.
        RateLimiter::for('verification', fn (Request $request) =>
            Limit::perMinute(6)->by($request->ip())
        );
    }

    /**
     * Strong password defaults for new admin accounts (UserResource form).
     * Enforced wherever Password::defaults() is called.
     */
    protected function configurePasswordDefaults(): void
    {
        Password::defaults(fn () => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised(),  // checks haveibeenpwned breach dataset
        );
    }
}
