<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Data\ClientPermission;
use App\ProjectRole;
use App\UserRole;

final class TaskCommentPolicy
{
    public function viewAny(User $user, Task $task): bool
    {
        return $user->can('view', $task);
    }

    public function view(User $user, TaskComment $comment): bool
    {
        return $this->viewAny($user, $comment->task);
    }

    public function create(User $user, Task $task): bool
    {
        if (! $this->viewAny($user, $task)) {
            return false;
        }

        if ($task->workspace->isClient($user)) {
            return $task->workspace->allowsClient($user, ClientPermission::TasksComment);
        }

        return true;
    }

    public function delete(User $user, TaskComment $comment): bool
    {
        if ($comment->isAuthoredBy($user)) {
            return true;
        }

        if ($comment->task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $comment->task->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
