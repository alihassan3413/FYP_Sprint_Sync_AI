<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

use App\Modules\Projects\Models\Sprint;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SprintData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $goal,
        public string $status,
        public string $status_label,
        public string $starts_on,
        public string $ends_on,
        public ?string $started_at,
        public ?string $completed_at,
        public int $project_id,
        public bool $is_current,
        public bool $is_upcoming,
        public bool $is_overdue,
        public int $task_count,
        public int $completed_task_count,
        public int $completion_percentage,
        public int $time_elapsed_percentage,
        public int $total_days,
        public int $days_remaining,
        public ?int $committed_task_count,
        public ?int $carried_over_task_count,
    ) {}

    public static function fromModel(Sprint $sprint): self
    {
        $taskCount = (int) ($sprint->tasks_count ?? 0);
        $completedCount = $sprint->status->isCompleted() && $sprint->completed_task_count !== null
            ? $sprint->completed_task_count
            : (int) ($sprint->completed_tasks_count ?? 0);

        return new self(
            id: $sprint->id,
            name: $sprint->name,
            goal: $sprint->goal,
            status: $sprint->status->value,
            status_label: $sprint->status->label(),
            starts_on: $sprint->starts_on->toDateString(),
            ends_on: $sprint->ends_on->toDateString(),
            started_at: $sprint->started_at?->toIso8601String(),
            completed_at: $sprint->completed_at?->toIso8601String(),
            project_id: $sprint->project_id,
            is_current: $sprint->isCurrent(),
            is_upcoming: $sprint->isUpcoming(),
            is_overdue: $sprint->isOverdue(),
            task_count: $taskCount,
            completed_task_count: $completedCount,
            completion_percentage: $taskCount === 0 ? 0 : (int) round(($completedCount / $taskCount) * 100),
            time_elapsed_percentage: $sprint->timeElapsedPercentage(),
            total_days: $sprint->totalDays(),
            days_remaining: $sprint->daysRemaining(),
            committed_task_count: $sprint->committed_task_count,
            carried_over_task_count: $sprint->carried_over_task_count,
        );
    }
}
