<?php

declare(strict_types=1);

use App\Modules\Archive\Http\Controllers\ArchiveController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('archive', 'workspace.archive.', function () {
    Route::get('/', [ArchiveController::class, 'index'])->name('index');
});
