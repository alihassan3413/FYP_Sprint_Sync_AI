<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class DeleteWorkspaceAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, User $owner): void
    {
        if ($owner->workspaces()->where('workspaces.id', '!=', $workspace->id)->doesntExist()) {
            throw WorkspaceException::cannotDeleteOnlyWorkspace();
        }

        $workspaceName = $workspace->name;

        DB::transaction(function () use ($workspace, $owner, $workspaceName) {
            $affectedUserIds = User::query()
                ->where('current_workspace_id', $workspace->id)
                ->pluck('id');

            $this->auditLogger->handle(
                $workspace,
                null,
                $owner,
                AuditAction::WORKSPACE_DELETED,
                "{$owner->name} deleted the workspace \"{$workspaceName}\".",
                $workspace,
            );

            $workspace->delete();

            User::query()
                ->whereIn('id', $affectedUserIds)
                ->get()
                ->each(function (User $user) {
                    $user->forceFill([
                        'current_workspace_id' => $user->workspaces()->value('workspaces.id'),
                    ])->save();
                });
        });
    }
}
