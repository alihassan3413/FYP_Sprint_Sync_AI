<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Database\Factories;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskComment>
 */
final class TaskCommentFactory extends Factory
{
    protected $model = TaskComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->sentence(),
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn () => ['task_id' => $task->id]);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
