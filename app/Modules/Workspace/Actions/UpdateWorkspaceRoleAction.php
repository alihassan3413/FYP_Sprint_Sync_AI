<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Models\WorkspaceRole;

final class UpdateWorkspaceRoleAction
{
    public function handle(WorkspaceRole $role, WorkspaceRoleData $data): WorkspaceRole
    {
        $role->update([
            'name' => $data->name,
            'permissions' => $data->permissions ?? [],
        ]);

        return $role->refresh();
    }
}
