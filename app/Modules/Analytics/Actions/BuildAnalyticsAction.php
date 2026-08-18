<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Data\AnalyticsData;
use App\Modules\Analytics\Data\AnalyticsScope;
use App\Modules\Analytics\Data\ProjectSummaryData;
use App\Modules\Analytics\Data\TaskAssigneeBreakdownData;
use App\Modules\Analytics\Data\TaskColumnBreakdownData;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BuildAnalyticsAction
{
    private const MAX_ASSIGNEES = 8;

    /**
     * @param  array{project_id?: int, from?: string, to?: string}  $filters
     */
    public function handle(AnalyticsScope $scope, array $filters): AnalyticsData
    {
        $accessibleProjectIds = $scope->accessibleProjects->pluck('id');

        $scopedProjectIds = isset($filters['project_id'])
            ? $accessibleProjectIds->intersect([(int) $filters['project_id']])
            : $accessibleProjectIds;

        $projectIds = $scopedProjectIds->values()->all();

        $totalTasks = $this->taskQuery($scope, $projectIds)->count();
        $completedTasks = $this->taskQuery($scope, $projectIds)
            ->whereHas('boardColumn', fn ($q) => $q->where('is_done', true))
            ->count();
        $overdueTasks = $this->taskQuery($scope, $projectIds)->overdue()->count();

        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : null;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : null;

        $meetingsQuery = Meeting::query()->whereIn('project_id', $projectIds)
            ->when($from, fn ($q) => $q->where('scheduled_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('scheduled_at', '<=', $to));

        return new AnalyticsData(
            total_tasks: $totalTasks,
            completed_tasks: $completedTasks,
            open_tasks: $totalTasks - $completedTasks,
            task_completion_percentage: $this->percentage($completedTasks, $totalTasks),
            overdue_tasks: $overdueTasks,
            tasks_by_column: $this->tasksByColumn($scope, $projectIds),
            tasks_by_assignee: $this->tasksByAssignee($scope, $projectIds),
            total_meetings: (clone $meetingsQuery)->count(),
            upcoming_meetings: (clone $meetingsQuery)->upcoming()->count(),
            past_meetings: (clone $meetingsQuery)->past()->count(),
            total_projects: $scope->accessibleProjects->count(),
            projects: $this->projectSummaries($scope, $scopedProjectIds),
            scope: $scope->label(),
        );
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    private function taskQuery(AnalyticsScope $scope, array $projectIds): Builder
    {
        return $this->applyScope(Task::query(), $scope, $projectIds);
    }

    /**
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @param  array<int, int>  $projectIds
     * @return TQuery
     */
    private function applyScope($query, AnalyticsScope $scope, array $projectIds)
    {
        $teamIds = $scope->teamProjectIdsWithin($projectIds);
        $personalIds = $scope->personalProjectIdsWithin($projectIds);
        $personalUserId = $scope->personalUserId;

        return $query->where(function ($outer) use ($teamIds, $personalIds, $personalUserId) {
            $outer->whereIn('tasks.project_id', $teamIds);

            if ($personalUserId !== null && $personalIds !== []) {
                $outer->orWhere(function ($inner) use ($personalIds, $personalUserId) {
                    $inner->whereIn('tasks.project_id', $personalIds)
                        ->where('tasks.assigned_to', $personalUserId);
                });
            }
        });
    }

    private function percentage(int $part, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($part / $total) * 100);
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, TaskColumnBreakdownData>
     */
    private function tasksByColumn(AnalyticsScope $scope, array $projectIds): array
    {
        return $this->applyScope(DB::table('tasks'), $scope, $projectIds)
            ->join('board_columns', 'board_columns.id', '=', 'tasks.board_column_id')
            ->selectRaw('board_columns.name as name, board_columns.is_done as is_done, MIN(board_columns.position) as position, COUNT(*) as count')
            ->groupBy('board_columns.name', 'board_columns.is_done')
            ->orderBy('position')
            ->get()
            ->map(fn ($row) => new TaskColumnBreakdownData(
                name: $row->name,
                is_done: (bool) $row->is_done,
                count: (int) $row->count,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<int, TaskAssigneeBreakdownData>
     */
    private function tasksByAssignee(AnalyticsScope $scope, array $projectIds): array
    {
        return $this->applyScope(DB::table('tasks'), $scope, $projectIds)
            ->leftJoin('users', 'users.id', '=', 'tasks.assigned_to')
            ->selectRaw("tasks.assigned_to as assignee_id, COALESCE(users.name, 'Unassigned') as name, COUNT(*) as count")
            ->groupBy('tasks.assigned_to', 'users.name')
            ->orderByDesc('count')
            ->limit(self::MAX_ASSIGNEES)
            ->get()
            ->map(fn ($row) => new TaskAssigneeBreakdownData(
                assignee_id: $row->assignee_id !== null ? (int) $row->assignee_id : null,
                name: $row->name,
                count: (int) $row->count,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $scopedProjectIds
     * @return array<int, ProjectSummaryData>
     */
    private function projectSummaries(AnalyticsScope $scope, Collection $scopedProjectIds): array
    {
        $projectIds = $scopedProjectIds->values()->all();

        $counts = $this->applyScope(DB::table('tasks'), $scope, $projectIds)
            ->join('board_columns', 'board_columns.id', '=', 'tasks.board_column_id')
            ->selectRaw('tasks.project_id as project_id, COUNT(*) as total, SUM(CASE WHEN board_columns.is_done = 1 THEN 1 ELSE 0 END) as completed')
            ->groupBy('tasks.project_id')
            ->get()
            ->keyBy('project_id');

        return $scope->accessibleProjects
            ->whereIn('id', $projectIds)
            ->map(function (Project $project) use ($counts) {
                $row = $counts->get($project->id);
                $total = $row !== null ? (int) $row->total : 0;
                $completed = $row !== null ? (int) $row->completed : 0;

                return new ProjectSummaryData(
                    id: $project->id,
                    name: $project->name,
                    total_tasks: $total,
                    completed_tasks: $completed,
                    completion_percentage: $this->percentage($completed, $total),
                );
            })
            ->values()
            ->all();
    }
}
