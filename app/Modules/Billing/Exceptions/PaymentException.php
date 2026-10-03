<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Exceptions\AppException;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Support\MoneyFormatter;
use App\Support\Errors\ErrorCode;

final class PaymentException extends AppException
{
    public static function invoiceNotIssued(): self
    {
        return new self(
            code: ErrorCode::PAYMENT_INVOICE_NOT_ISSUED,
            status: 422,
            message: 'Payments can only be recorded once an invoice has been issued.',
        );
    }

    public static function exceedsBalance(int $balanceMinor, Currency $currency): self
    {
        return new self(
            code: ErrorCode::PAYMENT_EXCEEDS_BALANCE,
            status: 422,
            message: $balanceMinor === 0
                ? 'This invoice is already fully paid.'
                : 'That is more than the '.MoneyFormatter::format($balanceMinor, $currency).' still owed. Overpayments are not supported.',
            meta: ['balance_minor' => $balanceMinor],
        );
    }

    public static function keyReused(): self
    {
        return new self(
            code: ErrorCode::PAYMENT_KEY_REUSED,
            status: 422,
            message: 'This payment form was already used for another invoice. Reload the page and try again.',
        );
    }
}
