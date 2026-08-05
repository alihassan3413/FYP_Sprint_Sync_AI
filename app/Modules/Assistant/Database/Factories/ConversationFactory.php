<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Database\Factories;

use App\Models\User;
use App\Modules\Assistant\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
final class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => null,
            'title' => fake()->sentence(3),
            'is_archived' => false,
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
        ];
    }
}
