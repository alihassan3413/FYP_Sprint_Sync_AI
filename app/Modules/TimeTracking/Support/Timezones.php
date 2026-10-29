<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class Timezones
{
    /** "Asia/Karachi (UTC+5)", "Asia/Kolkata (UTC+5:30)", "UTC". The offset is the one in force at $at (DST aware). */
    public static function label(string $timezone, ?CarbonInterface $at = null): string
    {
        $offset = CarbonImmutable::instance($at ?? now())->setTimezone($timezone)->format('P');
        $short = preg_replace(['/:00$/', '/^([+-])0/'], ['', '$1'], $offset);

        if ($timezone === 'UTC') {
            return 'UTC';
        }

        return $offset === '+00:00' ? "{$timezone} (UTC)" : "{$timezone} (UTC{$short})";
    }
}
