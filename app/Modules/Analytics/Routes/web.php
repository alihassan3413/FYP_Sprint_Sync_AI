<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('analytics', 'workspace.analytics.', function () {
    Route::get('/', [AnalyticsController::class, 'index'])->name('index');
});
