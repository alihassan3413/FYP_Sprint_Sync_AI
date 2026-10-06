<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where an invoice email is. Separate from InvoiceStatus: a failed email never
 * changes an issued invoice.
 *
 * - Pending: ready to send (the invoice is issued).
 * - Sending: one worker has claimed it; nobody else may send.
 * - Sent: the email server accepted it. Final.
 * - Failed: it definitely did not go out (could not connect, login refused,
 *   rejected before the message was handed over). Safe to retry.
 * - NeedsAttention: the server may have received it (the connection broke
 *   after the message was handed over). Not retried automatically; the owner
 *   checks and confirms before it is sent again.
 */
#[TypeScript]
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case NeedsAttention = 'needs_attention';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to send',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Email failed',
            self::NeedsAttention => 'Check delivery',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next === self::Sending,
            self::Sending => in_array($next, [self::Sent, self::Failed, self::NeedsAttention], true),
            self::Failed, self::NeedsAttention => $next === self::Pending,
            self::Sent => false,
        };
    }
}
