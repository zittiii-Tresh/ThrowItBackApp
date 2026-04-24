<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\Facades\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('SiteArchive')
            ->brandLogo(asset('brand-mark.svg'))   // 3 layered rounded squares, brand purple
            ->brandLogoHeight('1.75rem')           // sits comfortably next to the wordmark
            ->favicon(asset('favicon.svg'))
            ->darkMode(true)                       // dark/light toggle in top bar
            ->sidebarCollapsibleOnDesktop()
            ->spa()                                // faster nav, fewer full reloads
            ->colors([
                // Brand palette — #534AB7 purple from the SiteArchive proposal PDF.
                // Color::hex auto-generates the 50-950 shade ramp Filament uses internally.
                'primary' => Color::hex('#534AB7'),
                'danger'  => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info'    => Color::Sky,
            ])
            // Custom theme overlay — softer light-mode text + subtle purple
            // gradient backgrounds/borders + centered table cells. Loaded as
            // an inline <style> in <head> so no Vite rebuild is needed.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => View::make('filament.admin-theme')->render(),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Stock Filament widgets removed — our custom DashboardStats,
                // RecentCrawlRuns, and UpcomingCrawls are auto-discovered
                // from app/Filament/Widgets via discoverWidgets() above.
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Brute-force protection on /admin/login — 5 attempts per
                // minute per IP. Limiter defined in AppServiceProvider.
                'throttle:filament-login',
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
