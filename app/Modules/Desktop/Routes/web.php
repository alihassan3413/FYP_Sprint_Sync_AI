<?php

declare(strict_types=1);

use App\Modules\Desktop\Data\ReleasePlatform;
use App\Modules\Desktop\Http\Controllers\DesktopDownloadController;
use Illuminate\Support\Facades\Route;

/*
 * Desktop installer downloads. Platform and architecture come from fixed
 * lists; anything else is a 404 before a controller runs.
 */
$platforms = array_column(ReleasePlatform::cases(), 'value');

Route::middleware('throttle:30,1')->prefix('download/desktop')->name('desktop.download')->group(function () use ($platforms) {
    Route::get('uat/{platform}/{arch}', [DesktopDownloadController::class, 'uat'])
        ->middleware('auth')
        ->whereIn('platform', $platforms)
        ->whereIn('arch', ReleasePlatform::ARCHITECTURES)
        ->name('.uat');

    Route::get('{platform}/{arch}', [DesktopDownloadController::class, 'stable'])
        ->whereIn('platform', $platforms)
        ->whereIn('arch', ReleasePlatform::ARCHITECTURES)
        ->name('');
});
