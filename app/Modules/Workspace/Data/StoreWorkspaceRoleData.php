<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
class StoreWorkspaceRoleData extends Data
{
    public function __construct(
        #[Rule(['required', 'string', 'max:255'])]
        public string $name,

        #[Rule(['required', 'string', 'max:255'])]
        public string $slug,

        #[Rule(['nullable', 'array'])]
        #[TypeScriptType('Record<string, boolean> | null')]
        public ?array $permissions = null,
    ) {}
}
