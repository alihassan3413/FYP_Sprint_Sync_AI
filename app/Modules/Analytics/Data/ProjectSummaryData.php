<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProjectSummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $total_tasks,
        public int $completed_tasks,
        public int $completion_percentage,
    ) {}
}
