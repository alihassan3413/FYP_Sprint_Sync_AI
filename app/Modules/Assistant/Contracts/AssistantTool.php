<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Modules\Assistant\Support\ToolContext;

interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function requiresConfirmation(): bool;

    public function authorize(ToolContext $context): bool;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array;
}
