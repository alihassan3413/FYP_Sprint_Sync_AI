<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;

final class AcceptWorkspaceInvitationAction
{
    public function handle(WorkspaceInvitation $invitation, User $user): WorkspaceInvitation
    {
        if ($invitation->isAccepted()) {
            throw WorkspaceException::invitationInvalid('This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            throw WorkspaceException::invitationExpired($invitation->expires_at);
        }

        if (! hash_equals($invitation->email, $user->email)) {
            throw WorkspaceException::invitationInvalid('This invitation belongs to another email address.');
        }

        if ($invitation->workspace->hasMember($user)) {
            throw WorkspaceException::alreadyMember($invitation->workspace->name);
        }

        return DB::transaction(function () use ($invitation, $user) {
            $user->workspaces()->syncWithoutDetaching([
                $invitation->workspace_id => [
                    'role' => $invitation->role->value,
                    'workspace_role_id' => $this->resolveCustomRoleId($invitation),
                ],
            ]);

            $user->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();

            $invitation->update(['accepted_at' => now()]);

            return $invitation;
        });
    }

    /**
     * The custom role may have been deleted or moved between issuing and accepting the invitation.
     */
    private function resolveCustomRoleId(WorkspaceInvitation $invitation): ?int
    {
        if ($invitation->workspace_role_id === null) {
            return null;
        }

        $id = $invitation->workspace->roles()->whereKey($invitation->workspace_role_id)->value('id');

        return $id === null ? null : (int) $id;
    }
}
