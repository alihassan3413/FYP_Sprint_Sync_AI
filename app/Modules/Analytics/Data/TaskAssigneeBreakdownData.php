<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TaskAssigneeBreakdownData extends Data
{
    public function __construct(
        public ?int $assignee_id,
        public string $name,
        public int $count,
    ) {}
}
