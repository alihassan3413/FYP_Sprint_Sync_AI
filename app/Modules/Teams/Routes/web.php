<?php

declare(strict_types=1);

use App\Modules\Teams\Http\Controllers\TeamMemberController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

TenantRoute::prefixed('teams', 'workspace.teams.', function () {
    Route::get('/', [TeamMemberController::class, 'index'])->name('index');
});

TenantRoute::prefixed('members', 'workspace.members.', function () {
    Route::patch('{user}', [TeamMemberController::class, 'update'])->name('update');
    Route::delete('{user}', [TeamMemberController::class, 'destroy'])->name('destroy');
});
