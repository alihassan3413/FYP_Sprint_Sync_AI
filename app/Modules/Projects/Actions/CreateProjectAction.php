<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Modules\Projects\Data\StoreProjectData;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;

final class CreateProjectAction
{
    public function handle(Workspace $workspace, StoreProjectData $data): Project
    {
        return $workspace->projects()->create([
            'name' => $data->name,
            'description' => $data->description,
        ]);
    }
}
