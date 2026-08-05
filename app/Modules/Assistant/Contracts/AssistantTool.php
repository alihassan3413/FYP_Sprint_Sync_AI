<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Models\User;

interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function requiresConfirmation(): bool;

    public function authorize(User $user): bool;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, User $user): array;
}
