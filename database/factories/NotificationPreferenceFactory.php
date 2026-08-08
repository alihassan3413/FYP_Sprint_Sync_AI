<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
final class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(NotificationType::cases())->value,
            'channel' => fake()->randomElement(NotificationChannel::cases())->value,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }

    public function type(NotificationType $type): static
    {
        return $this->state(fn () => ['type' => $type->value]);
    }

    public function channel(NotificationChannel $channel): static
    {
        return $this->state(fn () => ['channel' => $channel->value]);
    }
}
