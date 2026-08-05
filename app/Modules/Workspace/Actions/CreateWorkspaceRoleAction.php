<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Modules\Workspace\Data\WorkspaceRoleData;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateWorkspaceRoleAction
{
    public function handle(Workspace $workspace, WorkspaceRoleData $data): WorkspaceRole
    {
        $slug = $data->slug !== '' && $data->slug !== null
            ? Str::slug($data->slug)
            : Str::slug($data->name);

        $this->ensureSlugIsAvailable($workspace, $slug);

        return $workspace->roles()->create([
            'name' => $data->name,
            'slug' => $slug,
            'permissions' => $data->permissions ?? [],
        ]);
    }

    private function ensureSlugIsAvailable(Workspace $workspace, string $slug): void
    {
        if (in_array($slug, UserRole::values(), true)) {
            throw ValidationException::withMessages([
                'name' => 'That name is reserved for a built-in role.',
            ]);
        }

        if ($workspace->roles()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A role with this name already exists in this workspace.',
            ]);
        }
    }
}
