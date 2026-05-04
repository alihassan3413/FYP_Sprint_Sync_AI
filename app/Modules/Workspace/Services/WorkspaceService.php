<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

final class WorkspaceService
{
    public function currentFor(?User $user): ?Workspace
    {
        if (! $user || ! $user->current_workspace_id) {
            return null;
        }

        return $user->workspaces()
            ->whereKey($user->current_workspace_id)
            ->first();
    }

    public function availableFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return $user->workspaces()
            ->select('workspaces.id', 'workspaces.name', 'workspaces.slug')
            ->orderBy('workspaces.name')
            ->get();
    }

    public function belongsTo(User $user, Workspace $workspace): bool
    {
        return $user->workspaces()
            ->whereKey($workspace->id)
            ->exists();
    }

    public function switchTo(User $user, Workspace $workspace): void
    {
        if (! $this->belongsTo($user, $workspace)) {
            abort(403);
        }

        $user->forceFill([
            'current_workspace_id' => $workspace->id,
        ])->save();
    }

    public function inertiaFor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $workspaces = $this->availableFor($user);

        $current = $workspaces->firstWhere('id', $user->current_workspace_id);

        return [
            'current' => $current ? [
                'id' => $current->id,
                'name' => $current->name,
                'role' => $current->pivot->role,
                'slug' => $current->slug,
            ] : null,

            'available' => $workspaces
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'role' => $workspace->pivot->role,
                    'is_current' => $workspace->id === $user->current_workspace_id,
                    'slug' => $workspace->slug,
                ])
                ->values()
                ->all(),
        ];
    }
}
