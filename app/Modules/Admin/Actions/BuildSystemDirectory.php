<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Paginated, searchable listings of every user and workspace on the platform.
 *
 * The two listings paginate independently so browsing one does not reset the
 * other, which is why each has its own page and search parameter.
 */
final class BuildSystemDirectory
{
    private const PER_PAGE = 10;

    public function users(?string $search): LengthAwarePaginator
    {
        return User::query()
            ->withCount('workspaces')
            ->when($search, fn ($query, string $term) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"),
            ))
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE, ['*'], 'users_page')
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_verified' => $user->email_verified_at !== null,
                'is_super_admin' => $user->isSuperAdmin(),
                'workspaces_count' => (int) $user->workspaces_count,
                'created_at' => $user->created_at?->toIso8601String(),
            ]);
    }

    public function workspaces(?string $search): LengthAwarePaginator
    {
        return Workspace::query()
            ->with('owner:id,name,email')
            ->withCount(['users', 'projects'])
            ->when($search, fn ($query, string $term) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"),
            ))
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE, ['*'], 'workspaces_page')
            ->withQueryString()
            ->through(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'is_active' => (bool) $workspace->is_active,
                'owner_name' => $workspace->owner?->name,
                'owner_email' => $workspace->owner?->email,
                'members_count' => (int) $workspace->users_count,
                'projects_count' => (int) $workspace->projects_count,
                'created_at' => $workspace->created_at?->toIso8601String(),
            ]);
    }
}
