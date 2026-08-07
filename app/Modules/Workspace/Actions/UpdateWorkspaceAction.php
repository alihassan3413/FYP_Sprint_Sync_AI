<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Modules\Workspace\Data\WorkspaceData;
use App\Modules\Workspace\Models\Workspace;

final class UpdateWorkspaceAction
{
    public function handle(Workspace $workspace, WorkspaceData $data): Workspace
    {
        $workspace->update([
            'name' => $data->name,
            'slug' => $data->slug,
        ]);

        return $workspace;
    }
}
