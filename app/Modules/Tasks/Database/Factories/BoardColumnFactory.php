<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Database\Factories;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardColumn>
 */
final class BoardColumnFactory extends Factory
{
    protected $model = BoardColumn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'position' => 0,
            'is_default' => false,
            'is_done' => false,
            'project_id' => Project::factory(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function done(): static
    {
        return $this->state(fn () => ['is_done' => true]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
