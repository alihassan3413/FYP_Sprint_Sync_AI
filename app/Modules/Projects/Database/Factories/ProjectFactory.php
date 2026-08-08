<?php

declare(strict_types=1);

namespace App\Modules\Projects\Database\Factories;

use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->catchPhrase(),
            'description' => fake()->optional()->sentence(),
            'workspace_id' => Workspace::factory(),
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn () => ['workspace_id' => $workspace->id]);
    }

    public function withoutDescription(): static
    {
        return $this->state(fn () => ['description' => null]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project) {
            if ($project->boardColumns()->exists()) {
                return;
            }

            $project->boardColumns()->createMany([
                ['name' => 'To Do', 'position' => 0, 'is_default' => true, 'is_done' => false],
                ['name' => 'In Progress', 'position' => 1, 'is_default' => true, 'is_done' => false],
                ['name' => 'Done', 'position' => 2, 'is_default' => true, 'is_done' => true],
            ]);
        });
    }
}
