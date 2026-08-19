<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class TranscriptionFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $meetingTitle,
        private readonly string $projectName,
        private readonly string $reason,
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
            'type' => 'transcription_failed',
            'title' => 'Transcription failed',
            'message' => "\"{$this->meetingTitle}\" in {$this->projectName} could not be transcribed automatically. {$this->reason} You can upload a transcript manually.",
            'url' => $this->url,
        ];
    }
}
