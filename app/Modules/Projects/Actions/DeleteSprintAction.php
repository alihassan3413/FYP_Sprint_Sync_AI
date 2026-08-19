<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Sprint;

final class DeleteSprintAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Sprint $sprint, User $actor): void
    {
        $project = $sprint->project;
        $name = $sprint->name;

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::SPRINT_DELETED,
            "{$actor->name} deleted sprint \"{$name}\".",
            $sprint,
        );

        $sprint->delete();
    }
}
