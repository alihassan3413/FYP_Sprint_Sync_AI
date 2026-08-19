<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Data\ClientPermission;
use App\ProjectRole;
use App\UserRole;

final class SprintPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        if (! $user->can('view', $project)) {
            return false;
        }

        if ($project->workspace->isClient($user)) {
            return $project->workspace->allowsClient($user, ClientPermission::BoardView);
        }

        return true;
    }

    public function view(User $user, Sprint $sprint): bool
    {
        return $this->viewAny($user, $sprint->project);
    }

    public function create(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function update(User $user, Sprint $sprint): bool
    {
        return $this->create($user, $sprint->project);
    }

    public function start(User $user, Sprint $sprint): bool
    {
        return $this->create($user, $sprint->project);
    }

    public function complete(User $user, Sprint $sprint): bool
    {
        return $this->create($user, $sprint->project);
    }

    public function delete(User $user, Sprint $sprint): bool
    {
        return $this->create($user, $sprint->project);
    }
}
