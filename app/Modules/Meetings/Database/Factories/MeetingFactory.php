<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Database\Factories;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Meeting>
 */
final class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'scheduled_at' => fake()->dateTimeBetween('now', '+2 weeks'),
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60, 90]),
            'meeting_link' => fake()->optional()->url(),
            'join_token' => Str::random(64),
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->workspace_id,
            'created_by' => User::factory(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'workspace_id' => $project->workspace_id,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => ['created_by' => $user->id]);
    }

    public function scheduledAt(string $dateTime): static
    {
        return $this->state(fn () => ['scheduled_at' => $dateTime]);
    }

    public function withParticipants(User ...$users): static
    {
        return $this->afterCreating(function ($meeting) use ($users) {
            foreach ($users as $user) {
                $meeting->participants()->create([
                    'user_id' => $user->id,
                    'email' => mb_strtolower($user->email),
                    'name' => $user->name,
                ]);
            }
        });
    }
}
