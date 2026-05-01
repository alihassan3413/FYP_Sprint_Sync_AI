<?php

namespace App\Modules\Workspace\Actions;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CreateWorkspaceInvitationAction
{
    public function handle(Workspace $workspace, User $invitedBy, WorkspaceInvitationData $data): WorkspaceInvitation
    {
        $invitation = WorkspaceInvitation::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'email' => $data->email,
            ],
            [
                'invited_by' => $invitedBy->id,
                'token' => Str::random(64),
                'role' => $data->role->value,
                'accepted_at' => null,
                'expires_at' => now()->addDays(7),
            ]
        );

        Mail::to($invitation->email)->send(new MemberInvitationMail(
            workspaceName: $workspace->name,
            invitedByName: $invitedBy->name,
            role: $data->role->value,
            invitationUrl: $invitation->token,
            expiresAt: $invitation->expires_at->format('F j, Y'),
        ));

        return $invitation;
    }
}
