<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\ProjectRole;
use App\UserRole;

final class TaskCommentPolicy
{
    public function viewAny(User $user, Task $task): bool
    {
        if ($task->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $task->project->hasMember($user);
    }

    public function create(User $user, Task $task): bool
    {
        return $this->viewAny($user, $task);
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
