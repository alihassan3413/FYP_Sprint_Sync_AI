<?php

declare(strict_types=1);

use App\Modules\Assistant\Http\Controllers\ChatController;
use App\Modules\Assistant\Http\Controllers\ConfirmActionController;
use App\Modules\Assistant\Http\Controllers\SpeechController;
use App\Modules\Assistant\Http\Controllers\VoiceTranscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'throttle:assistant-chat'])
    ->prefix('assistant')
    ->name('assistant.')
    ->group(function () {
        Route::post('chat', ChatController::class)->name('chat');
        Route::post('confirm', ConfirmActionController::class)->name('confirm');
    });

Route::middleware(['auth', 'verified', 'throttle:assistant-voice'])
    ->prefix('assistant')
    ->name('assistant.')
    ->group(function () {
        Route::post('voice/transcribe', VoiceTranscriptionController::class)->name('voice.transcribe');
        Route::post('voice/speak', SpeechController::class)->name('voice.speak');
    });
