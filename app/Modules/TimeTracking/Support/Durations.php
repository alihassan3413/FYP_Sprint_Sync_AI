<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

/** Seconds as people read them: "1h 05m", "14m". */
final class Durations
{
    public static function short(int $seconds): string
    {
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? sprintf('%dh %02dm', $hours, $minutes) : sprintf('%dm', $minutes);
    }
}
