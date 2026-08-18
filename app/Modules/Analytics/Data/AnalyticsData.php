<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AnalyticsData extends Data
{
    /**
     * @param  array<int, TaskColumnBreakdownData>  $tasks_by_column
     * @param  array<int, TaskAssigneeBreakdownData>  $tasks_by_assignee
     * @param  array<int, ProjectSummaryData>  $projects
     */
    public function __construct(
        public int $total_tasks,
        public int $completed_tasks,
        public int $open_tasks,
        public int $task_completion_percentage,
        public int $overdue_tasks,
        public array $tasks_by_column,
        public array $tasks_by_assignee,
        public int $total_meetings,
        public int $upcoming_meetings,
        public int $past_meetings,
        public int $total_projects,
        public array $projects,
        public string $scope,
    ) {}
}
