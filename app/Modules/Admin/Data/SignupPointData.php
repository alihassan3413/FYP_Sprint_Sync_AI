<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SignupPointData extends Data
{
    public function __construct(
        public string $date,
        public int $count,
    ) {}
}
