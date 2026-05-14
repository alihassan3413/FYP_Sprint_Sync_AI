<?php

namespace App\Modules\Workspace\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
class WorkspaceData extends Data
{
    public function __construct(
        #[TypeScriptType('string')]
        public string $name,

        #[TypeScriptType('string')]
        public string $slug,

        #[TypeScriptType('Record<string, any>|null')]
        public array $settings = [],

        #[TypeScriptType('boolean')]
        public bool $is_active = true,
    ) {}
}
