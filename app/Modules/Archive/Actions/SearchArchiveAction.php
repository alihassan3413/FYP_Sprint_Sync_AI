<?php

declare(strict_types=1);

namespace App\Modules\Archive\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SearchArchiveAction
{
    private const PER_PAGE = 20;

    /**
     * @return Collection<int, Project>
     */
    public function accessibleProjects(Workspace $workspace, User $user): Collection
    {
        $query = $workspace->projects()->orderBy('name');

        if (! $workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            $query->whereHas('members', fn ($q) => $q->whereKey($user->id));
        }

        return $query->get(['id', 'name']);
    }

    /**
     * @param  array{q?: string, type?: string, project_id?: int, assignee_id?: int, from?: string, to?: string}  $filters
     * @param  Collection<int, Project>  $accessibleProjects
     */
    public function handle(array $filters, Collection $accessibleProjects): LengthAwarePaginator
    {
        $accessibleProjectIds = $accessibleProjects->pluck('id');

        $scopedProjectIds = isset($filters['project_id'])
            ? $accessibleProjectIds->intersect([(int) $filters['project_id']])
            : $accessibleProjectIds;

        $type = $filters['type'] ?? null;
        $subQueries = [];

        if ($type !== 'meeting') {
            $subQueries[] = $this->completedTasksQuery($scopedProjectIds->all());
        }

        if ($type !== 'task') {
            $subQueries[] = $this->pastMeetingsQuery($scopedProjectIds->all());
        }

        $union = array_shift($subQueries);

        foreach ($subQueries as $query) {
            $union->unionAll($query);
        }

        $records = DB::query()->fromSub($union, 'archive_records');

        if (! empty($filters['q'])) {
            $keyword = '%'.$filters['q'].'%';
            $records->where(fn ($query) => $query->where('title', 'like', $keyword)->orWhere('subtitle', 'like', $keyword));
        }

        if (! empty($filters['assignee_id'])) {
            $records->where('assignee_id', (int) $filters['assignee_id']);
        }

        if (! empty($filters['from'])) {
            $records->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $records->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $records
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Collection<int, int>  $accessibleProjectIds
     * @return Collection<int, object{id: int, name: string}>
     */
    public function assigneeOptions(Collection $accessibleProjectIds): Collection
    {
        return DB::table('tasks')
            ->join('board_columns', 'board_columns.id', '=', 'tasks.board_column_id')
            ->join('users', 'users.id', '=', 'tasks.assigned_to')
            ->where('board_columns.is_done', true)
            ->whereIn('tasks.project_id', $accessibleProjectIds)
            ->distinct()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    private function completedTasksQuery(array $projectIds): Builder
    {
        return DB::table('tasks')
            ->join('board_columns', 'board_columns.id', '=', 'tasks.board_column_id')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->leftJoin('users as task_assignees', 'task_assignees.id', '=', 'tasks.assigned_to')
            ->where('board_columns.is_done', true)
            ->whereIn('tasks.project_id', $projectIds)
            ->select([
                'tasks.id',
                DB::raw("'task' as type"),
                'tasks.title',
                'tasks.description as subtitle',
                'tasks.project_id',
                'projects.name as project_name',
                'tasks.assigned_to as assignee_id',
                'task_assignees.name as assignee_name',
                'tasks.updated_at as occurred_at',
            ]);
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    private function pastMeetingsQuery(array $projectIds): Builder
    {
        return DB::table('meetings')
            ->join('projects', 'projects.id', '=', 'meetings.project_id')
            ->whereIn('meetings.project_id', $projectIds)
            ->whereRaw("datetime(meetings.scheduled_at, '+' || meetings.duration_minutes || ' minutes') < ?", [now()->toDateTimeString()])
            ->select([
                'meetings.id',
                DB::raw("'meeting' as type"),
                'meetings.title',
                'meetings.description as subtitle',
                'meetings.project_id',
                'projects.name as project_name',
                DB::raw('NULL as assignee_id'),
                DB::raw('NULL as assignee_name'),
                'meetings.scheduled_at as occurred_at',
            ]);
    }
}
