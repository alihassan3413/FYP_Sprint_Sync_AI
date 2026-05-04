<?php

use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix('{workspace}')
    ->scopeBindings()
    ->middleware(['auth', 'verified'])
    ->name('workspace.')
    ->group(function () {
        Route::get('/teams', function (Workspace $workspace) {
            $members = $workspace->users()
                ->select('users.id', 'users.name', 'users.email')
                ->get()
                ->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'status' => 'active',
                ]);

            return Inertia::render('teams/index', [
                'members' => $members,
            ]);
        })->name('teams.index');
    });
