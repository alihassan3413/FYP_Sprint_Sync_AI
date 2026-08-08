<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use App\ProjectRole;
use App\UserRole;

final class BoardColumnPolicy
{
    public function create(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function reorder(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function delete(User $user, BoardColumn $column): bool
    {
        if ($column->project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $column->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
