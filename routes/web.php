<?php

use App\Http\Controllers\ArchiveController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');

// Minimal archive playback. Phase 6 wraps these in a proper viewer UI
// (viewport switcher / page tabs / asset panel). For now they let you
// load a captured snapshot directly in the browser to verify the crawl.
Route::get('/archive/snapshot/{snapshot}',     [ArchiveController::class, 'snapshot'])->name('archive.snapshot');
Route::get('/archive/asset/{snapshot}/{hash}', [ArchiveController::class, 'asset'])->name('archive.asset');

/*
 |--------------------------------------------------------------------------
 | Email verification routes (for new admins)
 |--------------------------------------------------------------------------
 |
 | Wired to Laravel's MustVerifyEmail contract + the FilamentUser gate on
 | App\Models\User. When the UserResource creates a new admin it fires
 | Registered, Laravel emails a signed link pointing at verification.verify.
 |
 | The admin clicks → logs in (notice page bounces them here) → verifies →
 | redirected to /admin.
 */
Route::get('/email/verify', fn () => view('auth.verify-email'))
    ->middleware('auth')
    ->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect('/admin')->with('status', 'Email verified. Welcome to SiteArchive.');
    })
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('message', 'Verification link sent — check your inbox.');
    })
    ->middleware(['auth', 'throttle:6,1'])
    ->name('verification.send');

// Admin panel is mounted at /admin by the Filament AdminPanelProvider.
// Horizon dashboard is mounted at /horizon by HorizonServiceProvider.
