<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProjectHealthData extends Data
{
    /**
     * @param  array<int, HealthSignalData>  $signals
     * @param  array<int, WorkloadEntryData>  $workload
     */
    public function __construct(
        public int $project_id,
        public string $project_name,
        public string $verdict,
        public string $verdict_label,
        public int $total_tasks,
        public int $completed_tasks,
        public int $open_tasks,
        public int $completion_percentage,
        public int $overdue_tasks,
        public int $unassigned_open_tasks,
        public int $stale_open_tasks,
        public int $people_with_open_work,
        public int $busiest_share_percentage,
        public ?string $active_sprint_name,
        public ?string $active_sprint_health,
        public array $signals,
        public array $workload,
    ) {}
}
