<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use InvalidArgumentException;

/**
 * Turns a typed decimal ("2000", "5.55", "2.5") into an integer scaled by
 * 10^$decimals using string operations only, so user input never passes
 * through a float. Money in minor units is scaled(amount, 2); a percentage in
 * basis points is scaled(percent, 2) too (2.5% = 250).
 */
final class DecimalAmount
{
    public static function isValid(string $value, int $decimals, int $maxWholeDigits = 12): bool
    {
        return preg_match('/^\d{1,'.$maxWholeDigits.'}(\.\d{0,'.$decimals.'})?$/', trim($value)) === 1;
    }

    public static function scaled(string $value, int $decimals): int
    {
        $value = trim($value);

        if (! self::isValid($value, $decimals)) {
            throw new InvalidArgumentException("\"{$value}\" is not a valid amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.str_pad($fraction, $decimals, '0'));
    }
}
