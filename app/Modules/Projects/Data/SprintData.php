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
        public string $starts_on,
        public string $ends_on,
        public int $project_id,
        public bool $is_current,
        public bool $is_upcoming,
        public int $task_count,
    ) {}

    public static function fromModel(Sprint $sprint): self
    {
        return new self(
            id: $sprint->id,
            name: $sprint->name,
            goal: $sprint->goal,
            starts_on: $sprint->starts_on->toDateString(),
            ends_on: $sprint->ends_on->toDateString(),
            project_id: $sprint->project_id,
            is_current: $sprint->isCurrent(),
            is_upcoming: $sprint->isUpcoming(),
            task_count: (int) ($sprint->tasks_count ?? 0),
        );
    }
}
