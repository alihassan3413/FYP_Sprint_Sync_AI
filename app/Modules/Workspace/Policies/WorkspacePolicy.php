<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Policies;

use App\Models\User;
use App\Modules\Workspace\Data\WorkspacePermission;
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
        return $workspace->allows($user, WorkspacePermission::MembersRemove);
    }

    public function manageRoles(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::MembersRoles);
    }

    public function invite(User $user, Workspace $workspace): bool
    {
        return $workspace->allows($user, WorkspacePermission::MembersInvite);
    }
}
