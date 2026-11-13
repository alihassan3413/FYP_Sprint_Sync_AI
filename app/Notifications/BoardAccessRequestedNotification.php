<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class BoardAccessRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $requesterName,
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
            'type' => 'board_access_requested',
            'title' => 'Someone wants to add tasks',
            'message' => "{$this->requesterName} asked to add tasks to {$this->projectName}.",
            'url' => $this->url,
        ];
    }
}
