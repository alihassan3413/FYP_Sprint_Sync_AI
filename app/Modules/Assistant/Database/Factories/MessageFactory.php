<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Database\Factories;

use App\Modules\Assistant\Models\Conversation;
use App\Modules\Assistant\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => fake()->sentence(),
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function pendingTool(string $name, array $args = []): static
    {
        return $this->state(fn () => [
            'role' => 'tool',
            'content' => null,
            'tool_call_id' => 'call_'.Str::random(12),
            'tool_status' => Message::STATUS_PENDING,
            'metadata' => ['name' => $name, 'args' => $args],
        ]);
    }

    public function assistantUsage(string $model, int $inputTokens, int $outputTokens): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'provider' => 'openai',
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);
    }
}
