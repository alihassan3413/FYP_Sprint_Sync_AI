<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class MeetingUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $meetingTitle,
        private readonly string $scheduledAt,
        private readonly string $updatedByName,
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
            'type' => 'meeting_updated',
            'title' => 'Meeting updated',
            'message' => "{$this->updatedByName} updated \"{$this->meetingTitle}\" in {$this->projectName}. Now scheduled for {$this->scheduledAt}.",
            'url' => $this->url,
        ];
    }
}
