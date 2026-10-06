<?php

declare(strict_types=1);

use App\Modules\Desktop\Data\ReleaseChannel;
use App\Modules\Desktop\Models\DesktopRelease;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    /* Stable builds are public; signed-in testers also see UAT builds (their download route needs a session). */
    'desktopDownloads' => auth()->check()
        ? DesktopRelease::downloads(ReleaseChannel::Stable, ReleaseChannel::Uat)
        : DesktopRelease::downloads(ReleaseChannel::Stable),
]))->name('home');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/notifications.php';
