<?php

declare(strict_types=1);

namespace App\Modules\Teams\Actions;

use App\Models\User;
use App\Modules\Teams\Exceptions\TeamException;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Facades\DB;

final class RemoveWorkspaceMemberAction
{
    public function handle(Workspace $workspace, User $member, User $actor): void
    {
        if ($member->id === $actor->id) {
            throw TeamException::cannotRemoveSelf();
        }

        if ($workspace->roleFor($member) === UserRole::OWNER) {
            throw TeamException::cannotRemoveOwner();
        }

        DB::transaction(function () use ($workspace, $member) {
            $workspace->users()->detach($member->id);

            if ($member->current_workspace_id === $workspace->id) {
                $member->forceFill([
                    'current_workspace_id' => $member->workspaces()->value('workspaces.id'),
                ])->save();
            }
        });
    }
}
