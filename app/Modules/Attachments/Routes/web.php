<?php

declare(strict_types=1);

use App\Modules\Attachments\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('attachments')
    ->name('attachments.')
    ->group(function () {
        Route::post('/', [AttachmentController::class, 'store'])->name('store');
        Route::get('{attachment}', [AttachmentController::class, 'show'])->name('show');
        Route::delete('{attachment}', [AttachmentController::class, 'destroy'])->name('destroy');
    });
