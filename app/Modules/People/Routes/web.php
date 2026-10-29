<?php

declare(strict_types=1);

use App\Modules\People\Http\Controllers\PersonController;
use App\Modules\People\Http\Controllers\TeamImportController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

/*
 * Shown as "Team" in the product; the domain model underneath is Person.
 */
TenantRoute::prefixed('team', 'workspace.people.', function () {
    Route::get('/', [PersonController::class, 'index'])->name('index');
    Route::post('/', [PersonController::class, 'store'])->name('store');

    Route::get('import', [TeamImportController::class, 'create'])->name('imports.create');
    Route::get('import/template', [TeamImportController::class, 'template'])->name('imports.template');
    Route::post('import', [TeamImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
    Route::get('imports/{teamImport}', [TeamImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{teamImport}/confirm', [TeamImportController::class, 'confirm'])->name('imports.confirm');
    Route::post('imports/{teamImport}/cancel', [TeamImportController::class, 'cancel'])->name('imports.cancel');
    Route::get('imports/{teamImport}/issues', [TeamImportController::class, 'errors'])->name('imports.errors');
    Route::get('{person}', [PersonController::class, 'show'])->name('show');
    Route::put('{person}', [PersonController::class, 'update'])->name('update');
    Route::post('{person}/access', [PersonController::class, 'giveAccess'])
        ->middleware('throttle:invitation-send')
        ->name('access.store');
    Route::delete('{person}/user', [PersonController::class, 'unlinkUser'])->name('user.destroy');
});
