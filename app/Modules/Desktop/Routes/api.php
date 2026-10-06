<?php

declare(strict_types=1);

use App\Modules\Desktop\Http\Controllers\DesktopDownloadController;
use App\Modules\Desktop\Http\Controllers\DesktopSessionController;
use Illuminate\Support\Facades\Route;

/*
 * SprintSync Desktop sign-in. Stateless JSON (the "api" group: no session, no
 * CSRF cookie); everything except sign-in needs the desktop Bearer token.
 */
Route::prefix('api/desktop')->name('desktop.')->group(function () {
    Route::post('sign-in', [DesktopSessionController::class, 'store'])->middleware('throttle:desktop-login')->name('sign-in');

    Route::middleware('auth:desktop')->group(function () {
        Route::get('me', [DesktopSessionController::class, 'show'])->name('me');
        Route::post('sign-out', [DesktopSessionController::class, 'destroy'])->name('sign-out');
        Route::get('releases/latest', [DesktopDownloadController::class, 'latest'])->name('releases.latest');
    });
});
