<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the workspace owner an invoice email went out. A SprintSync
 * notification (database), not a client email, so it never uses the
 * workspace's mail server and carries no attachment.
 */
final class InvoiceSentNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $invoiceNumber,
        private readonly string $clientName,
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
            'type' => 'invoice_sent',
            'title' => 'Invoice sent',
            'message' => "{$this->invoiceNumber} was sent to {$this->clientName}.",
            'url' => $this->url,
        ];
    }
}
