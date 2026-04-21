<?php

use App\Http\Controllers\ArchiveController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'))->name('home');

// Minimal archive playback. Phase 6 wraps these in a proper viewer UI
// (viewport switcher / page tabs / asset panel). For now they let you
// load a captured snapshot directly in the browser to verify the crawl.
Route::get('/archive/snapshot/{snapshot}',           [ArchiveController::class, 'snapshot'])->name('archive.snapshot');
Route::get('/archive/asset/{snapshot}/{hash}',       [ArchiveController::class, 'asset'])->name('archive.asset');

// Admin panel is mounted at /admin by the Filament AdminPanelProvider.
// Horizon dashboard is mounted at /horizon by HorizonServiceProvider.
