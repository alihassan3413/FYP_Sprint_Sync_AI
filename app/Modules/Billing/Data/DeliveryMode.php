<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Review: the owner is reminded and sends it themselves.
 * Auto: it is sent on the issue day, unless a later safety check holds it back.
 */
#[TypeScript]
enum DeliveryMode: string
{
    case ReviewBeforeSending = 'review_before_sending';
    case AutoSend = 'auto_send';

    /**
     * Days before the issue day a review reminder may be sent (0 = that day).
     *
     * @return list<int>
     */
    public static function reminderOptions(): array
    {
        return [0, 1, 3, 7];
    }

    public const DEFAULT_REMINDER_DAYS = 3;
}
