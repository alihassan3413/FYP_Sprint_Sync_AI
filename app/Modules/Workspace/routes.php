<?php

use App\Modules\Workspace\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])
    ->prefix('workspace')
    ->name('workspace.')
    ->group(function () {

        Route::get('/invitations/create', function () {
            return Inertia::render('workspace/invitations/Create');
        })->name('invitations.create');


        Route::post('/invitations', [WorkspaceInvitationController::class, 'store'])
        ->name('invitations.store');
    });
