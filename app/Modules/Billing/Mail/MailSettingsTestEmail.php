<?php

declare(strict_types=1);

namespace App\Modules\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Send test email": proves the workspace's email server works. No invoice,
 * no attachment.
 */
final class MailSettingsTestEmail extends Mailable
{
    public function __construct(
        private readonly string $recipient,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            to: [$this->recipient],
            subject: 'SprintSync email delivery test',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p style="font-family:Arial,sans-serif;font-size:14px;color:#1f2328">This is a SprintSync email delivery test. Your invoice emails will be sent through this email server.</p>',
        );
    }
}
