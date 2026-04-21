<?php

use App\Http\Controllers\ArchiveController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
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
 | New admins created via UserResource can't log in until they verify —
 | FilamentUser::canAccessPanel() on App\Models\User checks hasVerifiedEmail.
 |
 | Flow (passwordless verification):
 |   1. Admin creates new user → Laravel emails signed /email/verify/…
 |   2. New user clicks the link from their email (not logged in yet)
 |   3. The signed URL itself proves they own the inbox → we mark verified
 |   4. Redirect to /admin/login → they enter their password → access.
 |
 | `auth` middleware is intentionally omitted because requiring login
 | before verification creates a chicken-and-egg for brand-new admins.
 */
Route::get('/email/verify/{id}/{hash}', function (Request $request, int $id, string $hash) {
        $user = User::find($id);
        if (! $user) {
            abort(404, 'Invalid verification link.');
        }

        // Verify the hash matches this user's email — Laravel's default
        // VerifyEmail notification derives the hash this way.
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Verification link is invalid or tampered with.');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect('/admin/login')->with('status', 'Your email is already verified — you can log in.');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect('/admin/login')->with('status', 'Email verified — log in to continue.');
    })
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// The notice + resend routes below are only reachable by a user who is
// already authenticated but somehow unverified — edge case, kept for
// completeness. New-admin flow uses verification.verify directly.
Route::get('/email/verify', fn () => view('auth.verify-email'))
    ->middleware('auth')
    ->name('verification.notice');

Route::post('/email/verification-notification', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('message', 'Verification link sent — check your inbox.');
    })
    ->middleware(['auth', 'throttle:6,1'])
    ->name('verification.send');

// Admin panel is mounted at /admin by the Filament AdminPanelProvider.
// Horizon dashboard is mounted at /horizon by HorizonServiceProvider.
