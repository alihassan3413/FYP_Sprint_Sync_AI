<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Data\WorkspaceInvitationData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class CreateWorkspaceInvitationAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, User $invitedBy, WorkspaceInvitationData $data): WorkspaceInvitation
    {
        $customRoleId = $this->resolveCustomRoleId($workspace, $data->workspace_role_id);

        $invitation = DB::transaction(fn () => WorkspaceInvitation::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'email' => $data->email,
            ],
            [
                'invited_by' => $invitedBy->id,
                'token' => Str::random(64),
                'role' => $data->role,
                'workspace_role_id' => $customRoleId,
                'accepted_at' => null,
                'expires_at' => now()->addDays(config('workspace.invitation_ttl_days')),
            ]
        ));

        $invitation->load('customRole');

        $this->auditLogger->handle(
            $workspace,
            null,
            $invitedBy,
            AuditAction::MEMBER_INVITED,
            "{$invitedBy->name} invited {$invitation->email} as {$invitation->roleLabel()}.",
            $invitation,
        );

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

    /**
     * Custom roles are workspace scoped, so an id from another workspace is dropped.
     */
    private function resolveCustomRoleId(Workspace $workspace, ?int $workspaceRoleId): ?int
    {
        if ($workspaceRoleId === null) {
            return null;
        }

        $id = $workspace->roles()->whereKey($workspaceRoleId)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function dispatchMail(Workspace $workspace, User $invitedBy, WorkspaceInvitation $invitation): void
    {
        Mail::to($invitation->email)->queue(new MemberInvitationMail(
            workspaceName: $workspace->name,
            invitedByName: $invitedBy->name,
            role: $invitation->roleLabel(),
            invitationUrl: route('workspace.invitations.accept', ['token' => $invitation->token]),
            expiresAt: $invitation->expires_at->format('F j, Y'),
        ));
    }
}
