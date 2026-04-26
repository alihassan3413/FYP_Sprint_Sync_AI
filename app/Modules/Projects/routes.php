<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('projects')
    ->name('projects.')
    ->group(function () {
        // Example:
        // Route::get('/', [ProjectsController::class, 'index'])->name('index');
    });
