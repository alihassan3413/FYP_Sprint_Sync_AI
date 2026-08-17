<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('audit-log', 'workspace.audit.', function () {
    Route::get('/', [AuditLogController::class, 'index'])->name('index');
});
