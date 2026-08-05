<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class AssistantQuotaException extends AppException
{
    public static function dailyCostExceeded(int $spentCents, int $limitCents): self
    {
        return new self(
            code: ErrorCode::ASSISTANT_QUOTA_EXCEEDED,
            status: 429,
            message: 'You have reached your daily assistant usage limit. It resets tomorrow.',
            meta: ['spent_cents' => $spentCents, 'limit_cents' => $limitCents],
            shouldReport: false,
        );
    }
}
