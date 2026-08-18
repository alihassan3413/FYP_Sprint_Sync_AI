<?php

declare(strict_types=1);

use App\Modules\Workspace\Http\Controllers\DashboardController;
use App\Modules\Workspace\Http\Controllers\WorkspaceController;
use App\Modules\Workspace\Http\Controllers\WorkspaceInvitationController;
use App\Modules\Workspace\Http\Controllers\WorkspaceInviteLinkController;
use App\Modules\Workspace\Http\Controllers\WorkspaceRoleController;
use App\Support\Routing\TenantRoute;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('workspaces')
    ->name('workspace.')
    ->group(function () {
        Route::post('/', [WorkspaceController::class, 'store'])->name('store');
        Route::post('{workspace:slug}/switch', [WorkspaceController::class, 'switch'])->name('switch');
    });

Route::prefix('invitations')
    ->name('workspace.invitations.')
    ->middleware('throttle:invitation-accept')
    ->group(function () {
        Route::get('accept/{token}', [WorkspaceInvitationController::class, 'showAccept'])->name('accept');
        Route::post('accept/{token}', [WorkspaceInvitationController::class, 'accept'])->name('accept.store');
    });

Route::prefix('join')
    ->name('workspace.join.')
    ->middleware('throttle:invitation-accept')
    ->group(function () {
        Route::get('{token}', [WorkspaceInviteLinkController::class, 'show'])->name('show');
        Route::post('{token}', [WorkspaceInviteLinkController::class, 'join'])->name('store');
    });

TenantRoute::group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::name('workspace.')->group(function () {
        Route::get('settings', [WorkspaceController::class, 'edit'])->name('settings');
        Route::put('settings', [WorkspaceController::class, 'update'])->name('update');
        Route::delete('settings', [WorkspaceController::class, 'destroy'])->name('destroy');

        Route::prefix('invitations')->name('invitations.')->group(function () {
            Route::get('create', [WorkspaceInvitationController::class, 'create'])->name('create');
            Route::post('/', [WorkspaceInvitationController::class, 'store'])
                ->middleware('throttle:invitation-send')
                ->name('store');
            Route::post('{invitation}/resend', [WorkspaceInvitationController::class, 'resend'])
                ->middleware('throttle:invitation-send')
                ->name('resend');
            Route::delete('{invitation}', [WorkspaceInvitationController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('invite-link')->name('invite-link.')->group(function () {
            Route::post('/', [WorkspaceInviteLinkController::class, 'store'])
                ->middleware('throttle:invitation-send')
                ->name('store');
            Route::delete('/', [WorkspaceInviteLinkController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [WorkspaceRoleController::class, 'index'])->name('index');
            Route::post('/', [WorkspaceRoleController::class, 'store'])->name('store');
            Route::put('{role}', [WorkspaceRoleController::class, 'update'])->name('update');
            Route::delete('{role}', [WorkspaceRoleController::class, 'destroy'])->name('destroy');
        });
    });
});
