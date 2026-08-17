<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;

final class RemoveProjectMemberAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, User $member, User $actor): void
    {
        $project->members()->detach($member->id);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::PROJECT_MEMBER_REMOVED,
            "{$actor->name} removed {$member->name} from \"{$project->name}\".",
            $member,
        );
    }
}
