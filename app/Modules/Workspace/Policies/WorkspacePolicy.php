<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Policies;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;

final class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::OWNER);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function manageRoles(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function invite(User $user, Workspace $workspace): bool
    {
        return $workspace->userHasAtLeast($user, UserRole::ADMIN);
    }
}
