<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
/**
 * The precise shapes of the nested arrays live in resources/js/lib/sprints.ts —
 * the generator here only handles flat types, so they are exported as unknown[].
 */
final class SprintReportData extends Data
{
    /**
     * The TypeScript shapes are declared on the properties themselves; the docblock
     * stays loose because the transformer cannot parse array shape syntax here.
     *
     * @param  array<int, mixed>  $burndown
     * @param  array<int, mixed>  $workload
     * @param  array<int, mixed>  $blockers
     * @param  array<string, int>  $column_breakdown
     * @param  array<int, string>  $recommendations
     */
    public function __construct(
        public int $sprint_id,
        public string $name,
        public ?string $goal,
        public string $status,
        public string $health,
        public string $health_label,
        public string $starts_on,
        public string $ends_on,
        public int $total_days,
        public int $days_elapsed,
        public int $days_remaining,
        public int $time_elapsed_percentage,
        public int $total_tasks,
        public int $completed_tasks,
        public int $open_tasks,
        public int $overdue_tasks,
        public int $unassigned_tasks,
        public int $completion_percentage,
        public int $expected_percentage,
        /** Positive means ahead of the calendar, negative means behind. */
        public int $pace_delta,
        public ?int $committed_task_count,
        public int $scope_added,
        public ?int $carried_over_task_count,
        public ?float $average_cycle_time_days,
        public ?float $velocity_average,
        #[TypeScriptType('unknown[]')]
        public array $burndown,
        #[TypeScriptType('unknown[]')]
        public array $workload,
        #[TypeScriptType('unknown[]')]
        public array $blockers,
        #[TypeScriptType('Record<string, number>')]
        public array $column_breakdown,
        #[TypeScriptType('string[]')]
        public array $recommendations,
        public string $summary,
    ) {}
}
