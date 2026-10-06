<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * How an adjustment's amount is worked out. A percentage is stored in basis
 * points (2% = 200) and applied to the subtotal; a fixed amount is stored in
 * minor units of the invoice currency.
 */
enum AdjustmentType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
