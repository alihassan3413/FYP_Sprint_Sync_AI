<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\TaskStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpdateTaskStatusData extends Data
{
    public function __construct(
        public TaskStatus $status,
    ) {}
}
