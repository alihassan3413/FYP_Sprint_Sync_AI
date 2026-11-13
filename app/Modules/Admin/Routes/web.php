<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
use App\Modules\Admin\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;

/*
 * Deliberately not a TenantRoute: the admin panel reads across every
 * workspace and is not scoped to the current one.
 */
Route::middleware(['auth', 'verified', EnsureUserIsSuperAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');
        Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware('throttle:20,1')->name('impersonate');
    });

Route::middleware('auth')->post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
