<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether an issued invoice has been paid. Separate from InvoiceStatus (which
 * is about issuing) and never stored: it is derived from the invoice total and
 * the sum of its active payments, so it can never disagree with them.
 */
#[TypeScript]
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public static function for(int $totalMinor, int $paidMinor): self
    {
        return match (true) {
            $paidMinor <= 0 => $totalMinor === 0 ? self::Paid : self::Unpaid,
            $paidMinor >= $totalMinor => self::Paid,
            default => self::PartiallyPaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
        };
    }
}
