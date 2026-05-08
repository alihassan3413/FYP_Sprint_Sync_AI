<?php

use App\Modules\Workspace\Http\Controllers\WorkspaceController;
use App\Modules\Workspace\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Workspace management (no workspace context needed)
Route::middleware(['auth', 'verified'])
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::post('/', [WorkspaceController::class, 'store'])
            ->name('store');

        Route::post('/{workspace:slug}/switch', [WorkspaceController::class, 'switch'])
            ->name('switch');
    });

// Workspace-scoped routes (need workspace context)
Route::middleware(['auth', 'verified'])
    ->prefix('{workspace:slug}')
    ->scopeBindings()
    ->name('workspace.')
    ->group(function () {
        Route::get('/invitations/create', function () {
            return Inertia::render('workspace/invitations/Create');
        })->name('invitations.create');

        Route::post('/invitations', [WorkspaceInvitationController::class, 'store'])
            ->name('invitations.store');
    });

// Guest-accessible invitation routes (no auth required)
Route::prefix('workspace')
    ->name('workspace.')
    ->group(function () {
        Route::get('/invitations/accept/{token}', [WorkspaceInvitationController::class, 'showAccept'])
            ->name('invitations.accept');

        Route::post('/invitations/accept/{token}', [WorkspaceInvitationController::class, 'accept'])
            ->name('invitations.accept.store');
    });
