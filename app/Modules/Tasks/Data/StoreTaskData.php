<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class StoreTaskData extends Data
{
    public function __construct(
        #[Rule(['required', 'string', 'min:2', 'max:150'])]
        public string $title,

        #[Rule(['nullable', 'string', 'max:5000'])]
        public ?string $description,

        #[Rule(['nullable', 'integer'])]
        public ?int $assigned_to,

        #[Rule(['nullable', 'date'])]
        public ?string $due_date,

        #[Rule(['nullable', 'integer'])]
        public ?int $sprint_id = null,
    ) {}
}
