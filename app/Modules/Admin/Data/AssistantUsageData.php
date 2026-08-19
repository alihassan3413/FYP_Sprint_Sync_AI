<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class AssistantUsageData extends Data
{
    /**
     * @param  array<int, ModelUsageData>  $by_model
     * @param  array<int, TopAssistantUserData>  $top_users
     */
    public function __construct(
        public int $conversations_total,
        public int $messages_total,
        public int $input_tokens,
        public int $output_tokens,
        public int $estimated_cost_cents,
        public int $cost_today_cents,
        public array $by_model,
        public array $top_users,
    ) {}
}
