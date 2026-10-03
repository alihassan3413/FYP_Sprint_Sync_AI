<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\ClientController;
use App\Modules\Billing\Http\Controllers\ClientLogoController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('clients', 'workspace.clients.', function () {
    Route::get('/', [ClientController::class, 'index'])->name('index');
    Route::post('/', [ClientController::class, 'store'])->name('store');
    Route::get('{client}', [ClientController::class, 'show'])->name('show');
    Route::put('{client}', [ClientController::class, 'update'])->name('update');
    Route::post('{client}/archive', [ClientController::class, 'archive'])->name('archive');
    Route::post('{client}/restore', [ClientController::class, 'restore'])->name('restore');

    Route::get('{client}/logo', [ClientLogoController::class, 'show'])->name('logo.show');
    Route::post('{client}/logo', [ClientLogoController::class, 'store'])->name('logo.store');
    Route::delete('{client}/logo', [ClientLogoController::class, 'destroy'])->name('logo.destroy');
});
