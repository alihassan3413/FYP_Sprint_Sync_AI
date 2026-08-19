<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\StoreSprintData;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;

final class CreateSprintAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, User $actor, StoreSprintData $data): Sprint
    {
        $sprint = $project->sprints()->create([
            'name' => $data->name,
            'goal' => $data->goal,
            'starts_on' => $data->starts_on,
            'ends_on' => $data->ends_on,
            'workspace_id' => $project->workspace_id,
        ]);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::SPRINT_CREATED,
            "{$actor->name} created sprint \"{$sprint->name}\".",
            $sprint,
        );

        return $sprint;
    }
}
