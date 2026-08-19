<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\ProjectMemberController;
use App\Modules\Projects\Http\Controllers\SprintController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('projects', 'workspace.projects.', function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::get('{project}', [ProjectController::class, 'show'])->name('show');
    Route::put('{project}', [ProjectController::class, 'update'])->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');
});

TenantRoute::prefixed('projects/{project}/members', 'workspace.projects.members.', function () {
    Route::post('/', [ProjectMemberController::class, 'store'])->name('store');
    Route::patch('{member}', [ProjectMemberController::class, 'update'])->name('update');
    Route::delete('{member}', [ProjectMemberController::class, 'destroy'])->name('destroy');
});

TenantRoute::prefixed('projects/{project}/sprints', 'workspace.projects.sprints.', function () {
    Route::post('/', [SprintController::class, 'store'])->name('store');
    Route::put('{sprint}', [SprintController::class, 'update'])->name('update');
    Route::delete('{sprint}', [SprintController::class, 'destroy'])->name('destroy');
});
