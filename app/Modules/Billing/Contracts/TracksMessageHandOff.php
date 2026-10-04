<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

/**
 * A transport that knows whether the message itself was handed to the server
 * (SMTP DATA accepted). A failure after that point is ambiguous: the server may
 * already have the email, so it must not be retried automatically.
 */
interface TracksMessageHandOff
{
    public function messageWasHandedOver(): bool;
}
