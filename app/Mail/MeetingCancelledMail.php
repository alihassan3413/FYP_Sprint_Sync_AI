<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class MeetingCancelledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $projectName,
        public string $meetingTitle,
        public string $scheduledAt,
        public string $cancelledByName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Meeting cancelled: {$this->meetingTitle}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.meeting-cancelled');
    }
}
