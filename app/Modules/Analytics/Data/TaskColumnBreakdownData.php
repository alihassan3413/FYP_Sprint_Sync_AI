<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TaskColumnBreakdownData extends Data
{
    public function __construct(
        public string $name,
        public bool $is_done,
        public int $count,
    ) {}
}
