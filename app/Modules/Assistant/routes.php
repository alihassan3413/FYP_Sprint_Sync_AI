<?php

declare(strict_types=1);

use App\Modules\Assistant\Http\Controllers\ChatController;
use App\Modules\Assistant\Http\Controllers\ConfirmActionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])
    ->prefix('api/assistant')
    ->name('assistant.')
    ->group(function () {
        Route::post('chat', ChatController::class)->name('chat');
        Route::post('confirm', ConfirmActionController::class)->name('confirm');
    });
