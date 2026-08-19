<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SprintProgressData extends Data
{
    /**
     * @param  array<int, SprintRefData>  $sprints
     * @param  array<int, TaskColumnBreakdownData>  $tasks_by_column
     */
    public function __construct(
        public bool $has_sprint,
        public array $sprints,
        public int $total_tasks,
        public int $completed_tasks,
        public int $open_tasks,
        public int $completion_percentage,
        public array $tasks_by_column,
    ) {}

    public static function none(): self
    {
        return new self(
            has_sprint: false,
            sprints: [],
            total_tasks: 0,
            completed_tasks: 0,
            open_tasks: 0,
            completion_percentage: 0,
            tasks_by_column: [],
        );
    }
}
