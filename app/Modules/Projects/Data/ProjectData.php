<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

use App\Modules\Projects\Models\Project;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProjectData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public int $workspace_id,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            id: $project->id,
            name: $project->name,
            description: $project->description,
            workspace_id: $project->workspace_id,
            created_at: $project->created_at->toIso8601String(),
            updated_at: $project->updated_at->toIso8601String(),
        );
    }
}
