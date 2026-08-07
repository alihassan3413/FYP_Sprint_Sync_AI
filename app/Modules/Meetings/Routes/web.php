<?php

declare(strict_types=1);

use App\Modules\Meetings\Http\Controllers\MeetingController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('projects/{project}/meetings', 'workspace.projects.meetings.', function () {
    Route::post('/', [MeetingController::class, 'store'])->name('store');
    Route::put('{meeting}', [MeetingController::class, 'update'])->name('update');
    Route::delete('{meeting}', [MeetingController::class, 'destroy'])->name('destroy');
});
