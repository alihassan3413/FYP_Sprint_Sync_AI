<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\MailTransportFactory;
use App\Modules\Billing\Contracts\TracksMessageHandOff;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Throwable;

/**
 * Sends a mailable through a workspace's own email server. There is no
 * fallback: if the workspace server fails, the email fails. Laravel's default
 * mailer (SprintSync's own) is never used for client-facing email.
 */
final class WorkspaceMailer
{
    public function __construct(
        private readonly MailTransportFactory $transports,
        private readonly ViewFactory $views,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return string|null the Message-ID the server accepted
     *
     * @throws MailDeliveryFailure
     */
    public function send(WorkspaceMailSettings $settings, Mailable $mailable): ?string
    {
        $transport = $this->transports->make($settings);

        try {
            $sent = (new Mailer('workspace', $this->views, $transport, $this->events))->send($mailable);
        } catch (Throwable $exception) {
            throw MailDeliveryFailure::from($exception, $transport instanceof TracksMessageHandOff && $transport->messageWasHandedOver());
        }

        return $sent?->getMessageId();
    }
}
