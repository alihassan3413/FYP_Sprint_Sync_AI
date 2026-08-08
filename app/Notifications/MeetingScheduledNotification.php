<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class MeetingScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $meetingTitle,
        private readonly string $scheduledAt,
        private readonly string $scheduledByName,
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
            'type' => 'meeting_scheduled',
            'title' => 'New meeting scheduled',
            'message' => "{$this->scheduledByName} scheduled \"{$this->meetingTitle}\" in {$this->projectName} for {$this->scheduledAt}.",
            'url' => $this->url,
        ];
    }
}
