<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Contracts\TracksMessageHandOff;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Symfony's ESMTP transport, noting the moment the server answered DATA with
 * 354: from then on the message is being handed over, so a dropped connection
 * may still mean the email was delivered.
 */
final class TrackedSmtpTransport extends EsmtpTransport implements TracksMessageHandOff
{
    private bool $handedOver = false;

    public function executeCommand(string $command, array $codes): string
    {
        $response = parent::executeCommand($command, $codes);

        if ($command === "DATA\r\n") {
            $this->handedOver = true;
        }

        return $response;
    }

    public function messageWasHandedOver(): bool
    {
        return $this->handedOver;
    }
}
