<?php

use App\Modules\Workspace\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])
    ->prefix('{workspace:slug}')
    ->scopeBindings()
    ->group(function () {                            // ← group wrapper
        Route::get('dashboard', DashboardController::class)
            ->name('dashboard');
    });

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

load_module_routes();
