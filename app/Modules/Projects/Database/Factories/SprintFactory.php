<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sprint>
 */
final class SprintFactory extends Factory
{
    protected $model = Sprint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Sprint '.fake()->unique()->numberBetween(1, 9999),
            'goal' => null,
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(),
            'project_id' => Project::factory(),
            'workspace_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->workspace_id,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => [
            'project_id' => $project->id,
            'workspace_id' => $project->workspace_id,
        ]);
    }

    public function current(): static
    {
        return $this->state(fn () => [
            'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->addDays(10)->toDateString(),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_on' => now()->subDays(30)->toDateString(),
            'ends_on' => now()->subDays(16)->toDateString(),
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn () => [
            'starts_on' => now()->addDays(7)->toDateString(),
            'ends_on' => now()->addDays(21)->toDateString(),
        ]);
    }
}
