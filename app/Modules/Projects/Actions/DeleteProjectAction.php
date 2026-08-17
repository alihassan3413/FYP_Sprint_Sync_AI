<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;

final class DeleteProjectAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, User $actor): void
    {
        $workspace = $project->workspace;
        $name = $project->name;

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::PROJECT_DELETED,
            "{$actor->name} deleted project \"{$name}\".",
            $project,
        );

        $project->delete();
    }
}
