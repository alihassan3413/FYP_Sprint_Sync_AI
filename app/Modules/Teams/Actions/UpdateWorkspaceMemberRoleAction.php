<?php

declare(strict_types=1);

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;

final class UpdateWorkspaceMemberRoleAction
{
    public function handle(Workspace $workspace, User $member, UserRole $role, ?WorkspaceRole $customRole = null): void
    {
        if ($workspace->roleFor($member) === UserRole::OWNER) {
            throw TeamException::cannotChangeOwnerRole();
        }

        if ($role === UserRole::OWNER) {
            throw TeamException::cannotChangeOwnerRole();
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'role' => $role->value,
            'workspace_role_id' => $customRole?->id,
        ]);
    }
}
