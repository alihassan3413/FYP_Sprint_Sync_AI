<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\BoardColumn;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class BoardColumnData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $position,
        public bool $is_default,
        public bool $is_done,
        public int $project_id,
    ) {}

    public static function fromModel(BoardColumn $column): self
    {
        return new self(
            id: $column->id,
            name: $column->name,
            position: $column->position,
            is_default: $column->is_default,
            is_done: $column->is_done,
            project_id: $column->project_id,
        );
    }
}
