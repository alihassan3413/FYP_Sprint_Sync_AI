<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class MeetingCancelledNotification extends Notification implements ShouldQueue
{
    use FormatsMeetingTime, Queueable;

    public function __construct(
        private readonly string $projectName,
        private readonly string $meetingTitle,
        private readonly string $scheduledAtUtc,
        private readonly string $cancelledByName,
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
        $scheduledAt = $this->localScheduledAt($notifiable, $this->scheduledAtUtc);

        return [
            'type' => 'meeting_cancelled',
            'title' => 'Meeting cancelled',
            'message' => "{$this->cancelledByName} cancelled \"{$this->meetingTitle}\" in {$this->projectName}. It was scheduled for {$scheduledAt}.",
            'url' => $this->url,
        ];
    }
}
