<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Http\Controllers\PreviousHoursController;
use App\Modules\TimeTracking\Http\Controllers\TimeEntryController;
use App\Modules\TimeTracking\Http\Controllers\TimeIndexController;
use App\Modules\TimeTracking\Http\Controllers\TimerController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('time', 'workspace.time.', function () {
    Route::get('/', TimeIndexController::class)->name('index');

    Route::get('timer', [TimerController::class, 'current'])->name('timer.current');
    Route::post('timer/start', [TimerController::class, 'start'])->name('timer.start');
    Route::post('timer/pause', [TimerController::class, 'pause'])->name('timer.pause');
    Route::post('timer/resume', [TimerController::class, 'resume'])->name('timer.resume');
    Route::post('timer/stop', [TimerController::class, 'stop'])->name('timer.stop');

    Route::post('entries', [TimeEntryController::class, 'store'])->name('entries.store');
    Route::post('entries/{timeEntry}/adjustments', [TimeEntryController::class, 'adjust'])->name('entries.adjust');
    Route::post('entries/{timeEntry}/void', [TimeEntryController::class, 'void'])->name('entries.void');

    Route::get('previous-hours', [PreviousHoursController::class, 'create'])->name('previous-hours.create');
    Route::post('previous-hours', [PreviousHoursController::class, 'store'])->name('previous-hours.store');
});
