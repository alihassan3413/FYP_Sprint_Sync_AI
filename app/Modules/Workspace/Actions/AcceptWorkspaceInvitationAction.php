<?php

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Validation\ValidationException;

final class AcceptWorkspaceInvitationAction
{
    public function handle(string $token, User $user): WorkspaceInvitation
    {
       $invitation = WorkspaceInvitation::query()->where('token', $token)->firstOrFail();

        if ($invitation->accepted_at) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation has already been accepted.',
            ]);
        }

         if ($invitation->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation has expired.',
            ]);
        }

        if ($user->email !== $invitation->email) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation belongs to another email address.',
            ]);
        }

        $user->workspaces()->syncWithoutDetaching([
            $invitation->workspace_id => [
                'role' => $invitation->role,
            ],
        ]);

        $user->forceFill([
            'current_workspace_id' => $invitation->workspace_id,
        ])->save();

        $invitation->update([
            'accepted_at' => now(),
        ]);

        return $invitation;
    }
}
