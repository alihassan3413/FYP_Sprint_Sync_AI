<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpdateTaskStatusData extends Data
{
    public function __construct(
        public int $board_column_id,
    ) {}
}
