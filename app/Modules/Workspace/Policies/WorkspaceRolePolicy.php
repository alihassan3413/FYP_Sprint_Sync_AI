<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Policies;

use App\Models\User;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;

final class WorkspaceRolePolicy
{
    public function view(User $user, WorkspaceRole $role): bool
    {
        return $role->workspace->hasMember($user);
    }

    public function update(User $user, WorkspaceRole $role): bool
    {
        return $role->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function delete(User $user, WorkspaceRole $role): bool
    {
        return $role->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }
}
