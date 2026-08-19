<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use App\Modules\Projects\Models\Sprint;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SprintRefData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $starts_on,
        public string $ends_on,
        public int $project_id,
        public string $project_name,
    ) {}

    public static function fromModel(Sprint $sprint, string $projectName): self
    {
        return new self(
            id: $sprint->id,
            name: $sprint->name,
            starts_on: $sprint->starts_on->toDateString(),
            ends_on: $sprint->ends_on->toDateString(),
            project_id: $sprint->project_id,
            project_name: $projectName,
        );
    }
}
