<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use Carbon\CarbonImmutable;

/**
 * Rules for the dates time is filed under (business dates, workspace calendar).
 */
final class TimePeriod
{
    public const MAX_DAY_SECONDS = 86400;

    /**
     * A period for hours added afterwards: not in the future, end on or after
     * start, and inside one calendar month, so a monthly total (and later a
     * monthly invoice) never has to guess which month the hours belong to.
     *
     * @return int the most seconds the period can hold (24h a day)
     */
    public static function assertHistorical(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today): int
    {
        if ($end->lessThan($start)) {
            throw TimeTrackingException::invalid('The period must end on or after the day it starts.');
        }

        if ($end->greaterThan($today)) {
            throw TimeTrackingException::invalid("Time can't be added for days that haven't happened yet.");
        }

        if ($start->format('Y-m') !== $end->format('Y-m')) {
            throw TimeTrackingException::invalid('Keep the period inside one month, so monthly totals stay exact. Add another month separately.');
        }

        return ((int) $start->diffInDays($end) + 1) * self::MAX_DAY_SECONDS;
    }
}
