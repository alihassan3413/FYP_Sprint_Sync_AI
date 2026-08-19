<?php

declare(strict_types=1);

namespace App\Modules\Projects\Data;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class StoreSprintData extends Data
{
    public function __construct(
        #[Rule(['required', 'string', 'min:2', 'max:80'])]
        public string $name,

        #[Rule(['nullable', 'string', 'max:2000'])]
        public ?string $goal,

        #[Rule(['required', 'date'])]
        public string $starts_on,

        #[Rule(['required', 'date'])]
        public string $ends_on,
    ) {}
}
