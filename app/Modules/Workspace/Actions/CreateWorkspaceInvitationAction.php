<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CreateWorkspaceInvitationAction
{
    public function handle(Workspace $workspace, User $invitedBy, WorkspaceInvitationData $data): WorkspaceInvitation
    {
        $invitation = DB::transaction(fn () => WorkspaceInvitation::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'email' => $data->email,
            ],
            [
                'invited_by' => $invitedBy->id,
                'token' => Str::random(64),
                'role' => $data->role,
                'accepted_at' => null,
                'expires_at' => now()->addDays(config('workspace.invitation_ttl_days')),
            ]
        ));

        $this->dispatchMail($workspace, $invitedBy, $invitation);

        return $invitation;
    }

    public function resend(Workspace $workspace, User $invitedBy, WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        $invitation->update([
            'token' => Str::random(64),
            'invited_by' => $invitedBy->id,
            'expires_at' => now()->addDays(config('workspace.invitation_ttl_days')),
        ]);

        $this->dispatchMail($workspace, $invitedBy, $invitation);

        return $invitation;
    }

    private function dispatchMail(Workspace $workspace, User $invitedBy, WorkspaceInvitation $invitation): void
    {
        Mail::to($invitation->email)->queue(new MemberInvitationMail(
            workspaceName: $workspace->name,
            invitedByName: $invitedBy->name,
            role: $invitation->role->label(),
            invitationUrl: route('workspace.invitations.accept', ['token' => $invitation->token]),
            expiresAt: $invitation->expires_at->format('F j, Y'),
        ));
    }
}
