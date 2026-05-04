<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/teams', function () {
        $workspace = auth()->user()->activeWorkspaceOrFail();

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
