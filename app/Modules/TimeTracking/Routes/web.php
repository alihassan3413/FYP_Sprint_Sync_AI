<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Http\Controllers\PreviousHoursController;
use App\Modules\TimeTracking\Http\Controllers\TimeEntryController;
use App\Modules\TimeTracking\Http\Controllers\TimeIndexController;
use App\Modules\TimeTracking\Http\Controllers\TimerController;
use App\Modules\TimeTracking\Http\Controllers\TimeReviewController;
use App\Modules\TimeTracking\Http\Controllers\TimeSettingsController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('time', 'workspace.time.', function () {
    Route::get('/', TimeIndexController::class)->name('index');

    Route::get('timer', [TimerController::class, 'current'])->name('timer.current');
    Route::post('timer/start', [TimerController::class, 'start'])->name('timer.start');
    Route::post('timer/pause', [TimerController::class, 'pause'])->name('timer.pause');
    Route::post('timer/resume', [TimerController::class, 'resume'])->name('timer.resume');
    Route::post('timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
    Route::post('timer/lunch-start', [TimerController::class, 'lunchStart'])->name('timer.lunch-start');
    Route::post('timer/lunch-end', [TimerController::class, 'lunchEnd'])->name('timer.lunch-end');
    Route::post('timer/idle-pause', [TimerController::class, 'idlePause'])->name('timer.idle-pause');

    Route::post('idle/{idlePeriod}/keep', [TimeReviewController::class, 'keepIdle'])->name('idle.keep');
    Route::post('idle/{idlePeriod}/drop', [TimeReviewController::class, 'dropIdle'])->name('idle.drop');
    Route::post('reviews/{review}/resolve', [TimeReviewController::class, 'resolve'])->name('reviews.resolve');

    Route::get('settings', [TimeSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings/policy', [TimeSettingsController::class, 'updatePolicy'])->name('settings.policy');
    Route::put('settings/schedule', [TimeSettingsController::class, 'saveSchedule'])->name('settings.schedule');
    Route::post('settings/time-off', [TimeSettingsController::class, 'storeTimeOff'])->name('settings.time-off');
    Route::delete('settings/holidays/{holiday}', [TimeSettingsController::class, 'destroyHoliday'])->name('settings.holidays.destroy');
    Route::delete('settings/leaves/{leave}', [TimeSettingsController::class, 'destroyLeave'])->name('settings.leaves.destroy');

    Route::post('entries', [TimeEntryController::class, 'store'])->name('entries.store');
    Route::post('entries/{timeEntry}/adjustments', [TimeEntryController::class, 'adjust'])->name('entries.adjust');
    Route::post('entries/{timeEntry}/void', [TimeEntryController::class, 'void'])->name('entries.void');

    Route::get('previous-hours', [PreviousHoursController::class, 'create'])->name('previous-hours.create');
    Route::post('previous-hours', [PreviousHoursController::class, 'store'])->name('previous-hours.store');
});
