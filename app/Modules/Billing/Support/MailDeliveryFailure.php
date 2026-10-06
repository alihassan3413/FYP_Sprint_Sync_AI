<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use RuntimeException;
use Throwable;

/**
 * A failed attempt to send through the workspace's email server, with a short
 * reason that is safe to show and store (never the server's raw reply, never
 * credentials) and whether the outcome is ambiguous (the message had already
 * been handed over when it failed).
 */
final class MailDeliveryFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $ambiguous, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    public static function from(Throwable $exception, bool $handedOver): self
    {
        $raw = strtolower($exception->getMessage());

        $reason = match (true) {
            $handedOver => 'The connection to the email server broke while the email was being delivered.',
            str_contains($raw, 'authenticat') || str_contains($raw, '535') || str_contains($raw, 'username and password') => 'The email server rejected the username or password.',
            str_contains($raw, 'timed out') || str_contains($raw, 'timeout') => 'The connection to the email server timed out.',
            str_contains($raw, 'ssl') || str_contains($raw, 'tls') || str_contains($raw, 'certificate') || str_contains($raw, 'crypto') => 'A secure connection to the email server could not be set up. Check the encryption setting.',
            str_contains($raw, 'connection could not be established') || str_contains($raw, 'connection refused') || str_contains($raw, 'getaddrinfo') || str_contains($raw, 'unable to connect') => 'Could not connect to the email server. Check the host and port.',
            str_contains($raw, 'expected response code') => 'The email server refused the message.',
            default => 'The email server could not send the message.',
        };

        return new self($reason, $handedOver, $exception);
    }
}
