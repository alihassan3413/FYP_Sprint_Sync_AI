<?php

declare(strict_types=1);

namespace App\Modules\Billing\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class DeliveryException extends AppException
{
    public static function mailNotConnected(): self
    {
        return new self(
            code: ErrorCode::DELIVERY_MAIL_NOT_CONNECTED,
            status: 422,
            message: 'Connect email delivery before sending invoices.',
        );
    }

    public static function notRetryable(): self
    {
        return new self(
            code: ErrorCode::DELIVERY_NOT_RETRYABLE,
            status: 422,
            message: 'This invoice email is not in a state that can be retried.',
        );
    }

    public static function needsConfirmation(): self
    {
        return new self(
            code: ErrorCode::DELIVERY_NEEDS_CONFIRMATION,
            status: 422,
            message: 'The client may already have this email. Confirm before sending it again.',
        );
    }
}
