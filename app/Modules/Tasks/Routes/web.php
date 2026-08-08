<?php

declare(strict_types=1);

use App\Modules\Tasks\Http\Controllers\BoardColumnController;
use App\Modules\Tasks\Http\Controllers\TaskCommentController;
use App\Modules\Tasks\Http\Controllers\TaskController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('projects/{project}/tasks', 'workspace.projects.tasks.', function () {
    Route::post('/', [TaskController::class, 'store'])->name('store');
    Route::put('{task}', [TaskController::class, 'update'])->name('update');
    Route::patch('{task}/status', [TaskController::class, 'updateStatus'])->name('update-status');
    Route::delete('{task}', [TaskController::class, 'destroy'])->name('destroy');
});

TenantRoute::prefixed('projects/{project}/tasks/{task}/comments', 'workspace.projects.tasks.comments.', function () {
    Route::post('/', [TaskCommentController::class, 'store'])->name('store');
    Route::delete('{comment}', [TaskCommentController::class, 'destroy'])->name('destroy');
});

TenantRoute::prefixed('projects/{project}/board-columns', 'workspace.projects.board-columns.', function () {
    Route::post('/', [BoardColumnController::class, 'store'])->name('store');
    Route::patch('reorder', [BoardColumnController::class, 'reorder'])->name('reorder');
    Route::delete('{boardColumn}', [BoardColumnController::class, 'destroy'])->name('destroy');
});
