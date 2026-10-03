<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Exceptions\AppException;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Support\Errors\ErrorCode;

final class InvoiceException extends AppException
{
    public static function invalidTransition(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self(
            code: ErrorCode::INVOICE_INVALID_TRANSITION,
            status: 422,
            message: "This invoice is {$from->label()} and cannot become {$to->label()}.",
            meta: ['from' => $from->value, 'to' => $to->value],
        );
    }

    public static function locked(InvoiceStatus $status): self
    {
        return new self(
            code: ErrorCode::INVOICE_LOCKED,
            status: 422,
            message: $status === InvoiceStatus::Approved
                ? 'This invoice is approved and scheduled to send. Cancel sending to change it.'
                : 'This invoice has been issued, so it can no longer be changed.',
        );
    }

    public static function alreadyIssued(): self
    {
        return new self(
            code: ErrorCode::INVOICE_LOCKED,
            status: 422,
            message: 'This invoice has already been issued. Sending can no longer be cancelled.',
        );
    }

    public static function changedDuringReview(): self
    {
        return new self(
            code: ErrorCode::INVOICE_CHANGED_DURING_REVIEW,
            status: 422,
            message: 'This invoice changed while you were reviewing it. Check it again, then approve.',
        );
    }

    public static function cannotGenerate(string $reason): self
    {
        return new self(code: ErrorCode::INVOICE_CANNOT_GENERATE, status: 422, message: $reason);
    }

    public static function senderIncomplete(string $step): self
    {
        return new self(
            code: ErrorCode::INVOICE_SENDER_INCOMPLETE,
            status: 422,
            message: "Complete invoicing details before {$step} this invoice.",
        );
    }

    public static function sendNotDue(): self
    {
        return new self(
            code: ErrorCode::INVOICE_SEND_NOT_DUE,
            status: 422,
            message: 'This invoice is still in its cancel window. It can be issued once the window has passed, or with Send now.',
        );
    }

    public static function negativeTotal(): self
    {
        return new self(
            code: ErrorCode::INVOICE_NEGATIVE_TOTAL,
            status: 422,
            message: 'The discounts are larger than the subtotal. The invoice total cannot be negative.',
        );
    }
}
