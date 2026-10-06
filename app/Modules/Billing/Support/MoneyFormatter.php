<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\Currency;

/**
 * Formats integer minor units for documents ("$5,059.20", "PKR 1,200.00")
 * without ever going through a float.
 */
final class MoneyFormatter
{
    public static function format(int $minor, Currency $currency): string
    {
        $sign = $minor < 0 ? '−' : '';
        $absolute = abs($minor);
        $amount = number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $currency === Currency::USD ? "{$sign}\${$amount}" : "{$sign}{$currency->value} {$amount}";
    }

    /** Hundredths to a document quantity: 16050 → "160.50". */
    public static function quantity(int $centi): string
    {
        return number_format(intdiv($centi, 100)).'.'.str_pad((string) ($centi % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Basis points to a percentage label: 200 → "2", 250 → "2.5". */
    public static function percentage(int $basisPoints): string
    {
        return rtrim(rtrim(intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0'), '.');
    }
}
