<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

/**
 * Typed hours ("2.5", "45.50", "-0.5") to integer seconds, without floats:
 * at most two decimals, so the value is whole hundredths of an hour and one
 * hundredth is exactly 36 seconds.
 */
final class HoursInput
{
    public static function toSeconds(?string $value, bool $allowNegative = false): ?int
    {
        $value = trim((string) $value);
        $pattern = $allowNegative ? '/^(-?)(\d{1,5})(?:\.(\d{1,2}))?$/' : '/^()(\d{1,5})(?:\.(\d{1,2}))?$/';

        if (! preg_match($pattern, $value, $parts)) {
            return null;
        }

        $centi = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] === '-' ? -1 : 1) * $centi * 36;
    }
}
