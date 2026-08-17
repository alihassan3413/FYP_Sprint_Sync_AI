<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;

final class CreateProjectAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, StoreProjectData $data, User $creator): Project
    {
        $project = $workspace->projects()->create([
            'name' => $data->name,
            'description' => $data->description,
        ]);

        $project->members()->attach($creator->id, ['role' => ProjectRole::MANAGER->value]);

        $project->boardColumns()->createMany([
            ['name' => 'To Do', 'position' => 0, 'is_default' => true, 'is_done' => false],
            ['name' => 'In Progress', 'position' => 1, 'is_default' => true, 'is_done' => false],
            ['name' => 'Done', 'position' => 2, 'is_default' => true, 'is_done' => true],
        ]);

        $this->auditLogger->handle(
            $workspace,
            $project,
            $creator,
            AuditAction::PROJECT_CREATED,
            "{$creator->name} created project \"{$project->name}\".",
            $project,
        );

        return $project;
    }
}
