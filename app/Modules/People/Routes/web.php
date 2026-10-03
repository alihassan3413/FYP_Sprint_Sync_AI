<?php

declare(strict_types=1);

use App\Modules\People\Http\Controllers\PersonController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

/*
 * Shown as "Team" in the product; the domain model underneath is Person.
 */
TenantRoute::prefixed('team', 'workspace.people.', function () {
    Route::get('/', [PersonController::class, 'index'])->name('index');
    Route::post('/', [PersonController::class, 'store'])->name('store');
    Route::get('{person}', [PersonController::class, 'show'])->name('show');
    Route::put('{person}', [PersonController::class, 'update'])->name('update');
    Route::post('{person}/access', [PersonController::class, 'giveAccess'])
        ->middleware('throttle:invitation-send')
        ->name('access.store');
    Route::delete('{person}/user', [PersonController::class, 'unlinkUser'])->name('user.destroy');
});
