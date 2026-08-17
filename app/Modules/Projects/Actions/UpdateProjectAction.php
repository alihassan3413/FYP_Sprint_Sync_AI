<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;

final class UpdateProjectAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, StoreProjectData $data, User $actor): Project
    {
        $project->update([
            'name' => $data->name,
            'description' => $data->description,
        ]);

        if ($project->wasChanged(['name', 'description'])) {
            $this->auditLogger->handle(
                $project->workspace,
                $project,
                $actor,
                AuditAction::PROJECT_UPDATED,
                "{$actor->name} updated project \"{$project->name}\".",
                $project,
            );
        }

        return $project->refresh();
    }
}
