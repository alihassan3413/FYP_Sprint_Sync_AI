<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\MailTransportFactory;
use App\Modules\Billing\Data\MailEncryption;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP connection from the workspace's saved settings at the moment
 * of sending; credentials are read from the encrypted column here and nowhere
 * else, and never travel through the queue.
 */
final class SmtpMailTransportFactory implements MailTransportFactory
{
    /** Seconds to wait for the server; a stuck server must not hang a worker. */
    public const TIMEOUT_SECONDS = 15;

    public function make(WorkspaceMailSettings $settings): TransportInterface
    {
        $transport = new TrackedSmtpTransport($settings->host, $settings->port, $settings->encryption === MailEncryption::Ssl);

        if ($settings->encryption === MailEncryption::Tls) {
            $transport->setAutoTls(true)->setRequireTls(true);
        }

        if ($settings->encryption === MailEncryption::None) {
            $transport->setAutoTls(false);
        }

        if (filled($settings->username)) {
            $transport->setUsername((string) $settings->username)->setPassword((string) $settings->password);
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }

        return $transport;
    }
}
