<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;

final class AddProjectMemberAction
{
    public function handle(Project $project, User $member, ProjectRole $role): void
    {
        $project->members()->syncWithoutDetaching([
            $member->id => ['role' => $role->value],
        ]);
    }
}
