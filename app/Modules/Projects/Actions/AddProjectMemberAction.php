<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;

final class AddProjectMemberAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, User $member, ProjectRole $role, User $actor): void
    {
        $project->members()->syncWithoutDetaching([
            $member->id => ['role' => $role->value],
        ]);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::PROJECT_MEMBER_ADDED,
            "{$actor->name} added {$member->name} to \"{$project->name}\" as Project {$role->label()}.",
            $member,
        );
    }
}
