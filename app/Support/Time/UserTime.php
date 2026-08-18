<?php

declare(strict_types=1);

namespace App\Support\Time;

use DateTimeZone;
use Illuminate\Support\Carbon;

final class UserTime
{
    public static function isValid(?string $timezone): bool
    {
        return $timezone !== null && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public static function resolve(?string $timezone): string
    {
        return self::isValid($timezone) ? $timezone : (string) config('app.timezone');
    }

    public static function toUtc(string $localDateTime, ?string $timezone): Carbon
    {
        return Carbon::parse($localDateTime, self::resolve($timezone))->utc();
    }

    public static function format(Carbon $utc, ?string $timezone, string $format = 'F j, Y g:i A'): string
    {
        $zone = self::resolve($timezone);

        return $utc->copy()->setTimezone($zone)->format($format).' ('.self::abbreviation($utc, $zone).')';
    }

    private static function abbreviation(Carbon $utc, string $zone): string
    {
        $localised = $utc->copy()->setTimezone($zone);
        $abbreviation = $localised->format('T');

        return str_contains($abbreviation, '+') || str_contains($abbreviation, '-')
            ? 'UTC'.$localised->format('P')
            : $abbreviation;
    }
}
