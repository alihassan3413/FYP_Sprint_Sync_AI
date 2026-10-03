<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which month an invoice issued on a given day covers. Hourly plans always
 * bill the previous month, because its hours are only known once it ends.
 */
#[TypeScript]
enum BillingPeriod: string
{
    case PreviousMonth = 'previous_month';
    case CurrentMonth = 'current_month';
}
