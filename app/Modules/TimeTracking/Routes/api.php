<?php

declare(strict_types=1);

use App\Modules\TimeTracking\Http\Controllers\DesktopTimeController;
use App\Modules\TimeTracking\Http\Controllers\TimerController;
use App\Modules\TimeTracking\Http\Controllers\TimeReviewController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

/*
 * The time API for SprintSync Desktop: the same controllers and actions as
 * the web page, authenticated by the desktop token instead of a session.
 * Workspace membership is still checked on every request.
 */
TenantRoute::desktop('time', 'desktop.time.', function () {
    Route::get('overview', DesktopTimeController::class)->name('overview');
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
    Route::post('activity', [TimeReviewController::class, 'activity'])->middleware('throttle:30,1')->name('activity');
});
