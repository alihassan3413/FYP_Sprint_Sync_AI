<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SearchAuditLogAction
{
    private const PER_PAGE = 25;

    /**
     * @param  array{user_id?: int, category?: string, project_id?: int, from?: string, to?: string}  $filters
     */
    public function handle(Workspace $workspace, User $viewer, array $filters): LengthAwarePaginator
    {
        $query = $this->scopedQuery($workspace, $viewer);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['category'])) {
            $query->whereIn('action', AuditAction::valuesForCategory($filters['category']));
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->with(['user:id,name,email,avatar_path', 'project:id,name'])
            ->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @return Collection<int, object{id: int, name: string}>
     */
    public function visibleProjects(Workspace $workspace, User $viewer): Collection
    {
        if ($workspace->userHasAtLeast($viewer, UserRole::ADMIN)) {
            return $workspace->projects()->orderBy('name')->get(['id', 'name']);
        }

        return $workspace->managedProjectsFor($viewer)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, User>
     */
    public function actorOptions(Workspace $workspace, User $viewer): Collection
    {
        $userIds = $this->scopedQuery($workspace, $viewer)->whereNotNull('user_id')->distinct()->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name']);
    }

    private function scopedQuery(Workspace $workspace, User $viewer): Builder
    {
        $query = AuditLog::query()->where('workspace_id', $workspace->id);

        if (! $workspace->userHasAtLeast($viewer, UserRole::ADMIN)) {
            $managedProjectIds = $workspace->managedProjectsFor($viewer)->pluck('id');
            $query->whereIn('project_id', $managedProjectIds);
        }

        return $query;
    }
}
