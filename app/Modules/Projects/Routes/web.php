<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('projects', 'workspace.projects.', function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::get('{project}', [ProjectController::class, 'show'])->name('show');
    Route::put('{project}', [ProjectController::class, 'update'])->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');
});
