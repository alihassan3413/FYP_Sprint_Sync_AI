<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Models\Workspace;

final class RevokeWorkspaceInviteLinkAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, User $actor): int
    {
        $revoked = $workspace->inviteLinks()->active()->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::INVITE_LINK_REVOKED,
                "{$actor->name} revoked the shareable invite link.",
            );
        }

        return $revoked;
    }
}
