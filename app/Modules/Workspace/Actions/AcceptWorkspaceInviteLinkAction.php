<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use App\UserRole;
use Illuminate\Support\Facades\DB;

final class AcceptWorkspaceInviteLinkAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(WorkspaceInviteLink $link, User $user): WorkspaceInviteLink
    {
        if ($link->isRevoked()) {
            throw WorkspaceException::invitationInvalid('This invite link has been revoked.');
        }

        if ($link->isExpired()) {
            throw WorkspaceException::invitationExpired($link->expires_at);
        }

        $workspace = $link->workspace;

        if ($workspace->hasMember($user)) {
            throw WorkspaceException::alreadyMember($workspace->name);
        }

        DB::transaction(function () use ($link, $user, $workspace) {
            $user->workspaces()->syncWithoutDetaching([
                $workspace->id => ['role' => UserRole::MEMBER->value],
            ]);

            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            $link->increment('uses');
        });

        $this->auditLogger->handle(
            $workspace,
            null,
            $user,
            AuditAction::INVITE_LINK_JOINED,
            "{$user->name} joined the workspace using a shareable invite link.",
            $user,
        );

        return $link->refresh();
    }
}
