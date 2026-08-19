<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TopAssistantUserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public int $messages,
        public int $input_tokens,
        public int $output_tokens,
        public int $estimated_cost_cents,
    ) {}
}
