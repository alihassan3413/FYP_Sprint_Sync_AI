<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\Workspace\Exceptions\WorkspaceException;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

final class WorkspaceService
{
    public function currentFor(?User $user): ?Workspace
    {
        if ($user === null || $user->current_workspace_id === null) {
            return null;
        }

        return $user->workspaces()->whereKey($user->current_workspace_id)->first();
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function availableFor(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        return $user->workspaces()
            ->select('workspaces.id', 'workspaces.name', 'workspaces.slug')
            ->orderBy('workspaces.name')
            ->get();
    }

    public function belongsTo(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function switchTo(User $user, Workspace $workspace): void
    {
        if (! $this->belongsTo($user, $workspace)) {
            throw WorkspaceException::notFound();
        }

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    }

    /**
     * @return array{current: array<string, mixed>|null, available: array<int, array<string, mixed>>}|null
     */
    public function inertiaFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $workspaces = $this->availableFor($user);

        /*
         * Falling back matters more than it looks: the front end builds every
         * workspace-scoped URL from this value, and when it is null those links
         * resolve to the login page. A stale `current_workspace_id` therefore
         * turned every button on the page into a silent no-op.
         */
        $current = $workspaces->firstWhere('id', $user->current_workspace_id) ?? $workspaces->first();

        return [
            'current' => $current === null ? null : $this->toArray($current, $user),
            'available' => $workspaces
                ->map(fn (Workspace $workspace) => $this->toArray($workspace, $user))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Workspace $workspace, User $user): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'role' => $workspace->pivot->role,
            'is_current' => $workspace->id === $user->current_workspace_id,
        ];
    }
}
