<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The invoice's financial state. Only the transitions in canTransitionTo()
 * exist; status is never assigned freely.
 *
 * - NeedsHours: an hourly invoice with at least one line still missing hours.
 * - ReadyToReview: every amount is known; editable; no number.
 * - Approved: the owner approved it and it is scheduled (send_after). Locked
 *   from editing, still no number, and the owner can cancel sending, which
 *   returns it to ReadyToReview. Auto-send invoices also wait in
 *   ReadyToReview until the send step approves them.
 * - Issued: issued to the client. The business number is assigned exactly
 *   here and the invoice can never change again; corrections later mean void
 *   and reissue.
 *
 * Email delivery (pending, sending, sent, failed) is deliberately not part of
 * this state: it is tracked separately when sending is built, so a failed
 * email never rewinds a financial record.
 */
#[TypeScript]
enum InvoiceStatus: string
{
    case NeedsHours = 'needs_hours';
    case ReadyToReview = 'ready_to_review';
    case Approved = 'approved';
    case Issued = 'issued';

    public function label(): string
    {
        return match ($this) {
            self::NeedsHours => 'Needs hours',
            self::ReadyToReview => 'Ready to review',
            self::Approved => 'Approved',
            self::Issued => 'Issued',
        };
    }

    /** Hours, amounts and lines can be changed through the normal editing flow. */
    public function isEditable(): bool
    {
        return $this === self::NeedsHours || $this === self::ReadyToReview;
    }

    /** Lines, adjustments and amounts are fixed (approved and waiting, or issued). */
    public function locksFinancials(): bool
    {
        return $this === self::Approved || $this === self::Issued;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::NeedsHours => $next === self::ReadyToReview,
            self::ReadyToReview => $next === self::NeedsHours || $next === self::Approved,
            self::Approved => $next === self::ReadyToReview || $next === self::Issued,
            self::Issued => false,
        };
    }
}
