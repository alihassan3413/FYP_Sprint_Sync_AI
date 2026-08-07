<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Database\Factories;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
final class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => TaskStatus::TODO,
            'due_date' => null,
            'assigned_to' => null,
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

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id]);
    }

    public function withStatus(TaskStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function withDueDate(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }
}
