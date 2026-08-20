<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class AssistantCommandData extends Data
{
    /**
     * @param  string[]  $keywords
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $category,
        #[TypeScriptType('string[]')]
        public array $keywords,
        public string $template,
        public bool $requires_confirmation,
    ) {}
}
