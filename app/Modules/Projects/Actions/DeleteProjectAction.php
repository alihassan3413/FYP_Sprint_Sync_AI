<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Models\Project;

final class DeleteProjectAction
{
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
