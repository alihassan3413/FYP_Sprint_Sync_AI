<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateWorkspaceRoleAction
{
    public function handle(Workspace $workspace, WorkspaceRoleData $data): WorkspaceRoleData
    {
        $slug = $data->slug ?: Str::slug($data->name);

        $this->ensureSlugIsUnique($workspace, $slug);

        $role = WorkspaceRole::create([
            'workspace_id' => $workspace->id,
            'name' => $data->name,
            'slug' => $slug,
            'permissions' => $data->permissions ?? [],
        ]);

        return WorkspaceRoleData::fromModel($role);
    }

    private function ensureSlugIsUnique(Workspace $workspace, string $slug): void
    {
        $exists = WorkspaceRole::query()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => 'A role with this name already exists in this workspace.',
            ]);
        }
    }
}
