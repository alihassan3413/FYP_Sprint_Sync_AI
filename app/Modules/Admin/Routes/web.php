<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Modules\Admin\Http\Controllers\AdminDashboardController;
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
    });
