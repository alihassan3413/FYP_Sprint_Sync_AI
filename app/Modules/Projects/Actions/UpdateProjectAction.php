<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;

final class UpdateProjectAction
{
    public function handle(Project $project, StoreProjectData $data): Project
    {
        $project->update([
            'name' => $data->name,
            'description' => $data->description,
        ]);

        return $project->refresh();
    }
}
