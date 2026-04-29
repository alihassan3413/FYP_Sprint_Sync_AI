<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        // Example:
        // Route::get('/', [WorkspaceController::class, 'index'])->name('index');
    });
