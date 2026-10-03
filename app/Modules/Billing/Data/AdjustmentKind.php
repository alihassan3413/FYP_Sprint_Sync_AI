<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * What an invoice adjustment does to the total. Fees and taxes add to it,
 * discounts take away. The amount itself is always stored as a positive number.
 */
enum AdjustmentKind: string
{
    case Fee = 'fee';
    case Tax = 'tax';
    case Discount = 'discount';

    public function sign(): int
    {
        return $this === self::Discount ? -1 : 1;
    }
}
