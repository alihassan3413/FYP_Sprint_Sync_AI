<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class TaskCommentPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $taskTitle,
        private readonly string $commenterName,
        private readonly string $excerpt,
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
            'type' => 'task_comment',
            'title' => 'New comment on your task',
            'message' => "{$this->commenterName} commented on \"{$this->taskTitle}\": \"{$this->excerpt}\"",
            'url' => $this->url,
        ];
    }
}
