<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class TaskMovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $taskTitle,
        private readonly string $columnName,
        private readonly string $movedByName,
        private readonly string $url,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_moved',
            'title' => 'Task moved',
            'message' => "{$this->movedByName} moved \"{$this->taskTitle}\" to {$this->columnName} in {$this->projectName}.",
            'url' => $this->url,
        ];
    }
}
