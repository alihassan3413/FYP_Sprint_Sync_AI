<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInviteLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GenerateWorkspaceInviteLinkAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, User $creator): WorkspaceInviteLink
    {
        $link = DB::transaction(function () use ($workspace, $creator) {
            $workspace->inviteLinks()->active()->update(['revoked_at' => now()]);

            return $workspace->inviteLinks()->create([
                'created_by' => $creator->id,
                'token' => Str::random(64),
                'expires_at' => now()->addDays((int) config('workspace.invitation_ttl_days')),
            ]);
        });

        $this->auditLogger->handle(
            $workspace,
            null,
            $creator,
            AuditAction::INVITE_LINK_GENERATED,
            "{$creator->name} generated a shareable invite link.",
            $link,
        );

        return $link;
    }
}
