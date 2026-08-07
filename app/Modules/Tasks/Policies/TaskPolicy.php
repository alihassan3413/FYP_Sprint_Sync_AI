<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\ProjectRole;
use App\UserRole;

final class TaskPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->hasMember($user);
    }

    public function view(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $task->project->hasMember($user);
    }

    public function create(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $task->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function updateStatus(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        if ($task->project->userHasAtLeast($user, ProjectRole::MANAGER)) {
            return true;
        }

        return $task->isAssignedTo($user);
    }

    public function delete(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $task->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
