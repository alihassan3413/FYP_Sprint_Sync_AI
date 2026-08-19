<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Data\ClientPermission;
use App\ProjectRole;
use App\UserRole;

final class TaskPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        if ($project->workspace->isClient($user)) {
            return $project->hasMember($user)
                && $project->workspace->allowsClient($user, ClientPermission::BoardView);
        }

        return $project->hasMember($user);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->viewAny($user, $task->project);
    }

    public function create(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        if ($project->workspace->isClient($user)) {
            return $project->hasMember($user)
                && $project->workspace->allowsClient($user, ClientPermission::TasksRequest);
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

        if ($task->workspace->isClient($user)) {
            return $task->project->hasMember($user)
                && $task->workspace->allowsClient($user, ClientPermission::TasksClose);
        }

        if ($task->project->userHasAtLeast($user, ProjectRole::MANAGER)) {
            return true;
        }

        return $task->isAssignedTo($user);
    }

    /**
     * Clients may only close a task — never move one back out of a done column.
     */
    public function moveToColumn(User $user, Task $task, BoardColumn $column): bool
    {
        if (! $this->updateStatus($user, $task)) {
            return false;
        }

        if (! $task->workspace->isClient($user)) {
            return true;
        }

        return $column->project_id === $task->project_id && $column->is_done;
    }

    public function delete(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $task->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
