<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/teams', function () {
        return Inertia::render('teams/index', [
            'members' => [
                [
                    'id' => 1,
                    'name' => auth()->user()->name,
                    'email' => auth()->user()->email,
                    'role' => 'owner',
                    'status' => 'active',
                ],
            ],
        ]);
    })->name('teams.index');
});
