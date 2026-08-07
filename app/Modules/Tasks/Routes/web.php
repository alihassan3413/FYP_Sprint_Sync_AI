<?php

declare(strict_types=1);

use App\Modules\Tasks\Http\Controllers\TaskController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('projects/{project}/tasks', 'workspace.projects.tasks.', function () {
    Route::post('/', [TaskController::class, 'store'])->name('store');
    Route::put('{task}', [TaskController::class, 'update'])->name('update');
    Route::patch('{task}/status', [TaskController::class, 'updateStatus'])->name('update-status');
    Route::delete('{task}', [TaskController::class, 'destroy'])->name('destroy');
});
