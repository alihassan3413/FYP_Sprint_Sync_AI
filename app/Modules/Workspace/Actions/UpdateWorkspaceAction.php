<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Models\Workspace;

final class UpdateWorkspaceAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, WorkspaceData $data, User $actor): Workspace
    {
        $previousName = $workspace->name;
        $previousTimezone = $workspace->timezone;

        $workspace->update([
            'name' => $data->name,
            'slug' => $data->slug,
            'timezone' => $data->timezone ?? $workspace->timezone,
        ]);

        if ($workspace->wasChanged('name')) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::WORKSPACE_RENAMED,
                "{$actor->name} renamed the workspace from \"{$previousName}\" to \"{$workspace->name}\".",
                $workspace,
            );
        }

        if ($workspace->wasChanged('timezone')) {
            $this->auditLogger->handle(
                $workspace,
                null,
                $actor,
                AuditAction::WORKSPACE_TIMEZONE_CHANGED,
                "{$actor->name} changed the workspace timezone from {$previousTimezone} to {$workspace->timezone}.",
                $workspace,
                ['from' => $previousTimezone, 'to' => $workspace->timezone],
            );
        }

        return $workspace;
    }
}
