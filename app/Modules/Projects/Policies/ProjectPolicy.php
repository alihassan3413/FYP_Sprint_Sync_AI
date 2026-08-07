<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;

final class ProjectPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function view(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->hasMember($user);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function update(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
