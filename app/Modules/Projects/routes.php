<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('{workspace:slug}/projects')
    ->scopeBindings()
    ->name('workspace.projects.')
    ->group(function () {
        // Example:
        // Route::get('/', [ProjectsController::class, 'index'])->name('index');
    });
