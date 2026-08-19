<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Events;

use App\Modules\Tasks\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TaskStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $taskId,
        public readonly int $projectId,
        public readonly int $boardColumnId,
        public readonly string $updatedAt,
    ) {}

    public static function fromTask(Task $task): self
    {
        return new self(
            taskId: $task->id,
            projectId: $task->project_id,
            boardColumnId: $task->board_column_id,
            updatedAt: $task->updated_at->toIso8601String(),
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("project.{$this->projectId}");
    }

    public function broadcastAs(): string
    {
        return 'task.status-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'project_id' => $this->projectId,
            'board_column_id' => $this->boardColumnId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
