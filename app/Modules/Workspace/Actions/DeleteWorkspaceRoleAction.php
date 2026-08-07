<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Modules\Workspace\Models\WorkspaceRole;
use Illuminate\Support\Facades\DB;

final class DeleteWorkspaceRoleAction
{
    public function handle(WorkspaceRole $role): void
    {
        DB::transaction(function () use ($role) {
            DB::table('workspace_users')
                ->where('workspace_role_id', $role->id)
                ->update(['workspace_role_id' => null]);

            $role->delete();
        });
    }
}
