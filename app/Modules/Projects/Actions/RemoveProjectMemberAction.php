<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;

final class RemoveProjectMemberAction
{
    public function handle(Project $project, User $member): void
    {
        $project->members()->detach($member->id);
    }
}
