<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('project.{projectId}', function (User $user, int $projectId) {
    $project = Project::query()->find($projectId);

    return $project !== null && $user->can('view', $project);
});
