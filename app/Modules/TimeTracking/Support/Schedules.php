<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Expected schedules. They never create hours: they are only compared with
 * real timer times ("1h 20m outside scheduled hours"), and nobody's timer is
 * stopped at the end of a shift.
 *
 * A person's own schedule wins over the workspace's; a version with no days
 * means "no schedule". Shifts that end at or before they start finish the
 * next day (18:00–02:00), and windows are built in the schedule's timezone,
 * so daylight-saving changes are handled by real clock times.
 */
final class Schedules
{
    public function for(Person $person, ?CarbonInterface $at = null): ?WorkSchedule
    {
        $at = ($at ?? now())->copy()->utc();
        $latest = fn (?int $personId) => WorkSchedule::query()
            ->where('workspace_id', $person->workspace_id)
            ->where('person_id', $personId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        $own = $latest($person->id);
        $schedule = ($own !== null && ! $own->isEmpty()) ? $own : $latest(null);

        return $schedule === null || $schedule->isEmpty() ? null : $schedule;
    }

    /**
     * The business day ("workday") a moment belongs to for this person.
     *
     * If the previous day's scheduled shift (an overnight one, e.g. 18:00–03:00)
     * contains the moment, it is that shift's day: a timer started at 00:40
     * belongs to the shift that began the evening before. Otherwise it is the
     * moment's own date in the workspace's timezone.
     *
     * Deterministic: the schedule is the version in force at that moment, and
     * schedule versions are never edited, so a later schedule change never
     * reclassifies earlier time.
     */
    public function workday(Person $person, CarbonInterface $at, string $timezone): string
    {
        $at = CarbonImmutable::instance($at)->utc();
        $schedule = $this->for($person, $at);

        if ($schedule !== null) {
            $previousDay = $at->setTimezone($schedule->timezone)->startOfDay()->subDay();

            if (in_array($previousDay->dayOfWeekIso, $schedule->days ?? [], true)) {
                [$start, $end] = $this->window($schedule, $previousDay);

                if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                    return $previousDay->toDateString();
                }
            }
        }

        return $at->setTimezone($timezone)->toDateString();
    }

    /** The workday a timer session belongs to: resolved from when it started, so everything in it (work, lunch, idle kept) shares one day. */
    public function workdayOf(TimeSession $session): string
    {
        return $this->workday($session->person, $session->started_at, $session->timezone);
    }

    /**
     * Seconds of these working stretches that fall outside the schedule.
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $stretches
     */
    public function outsideSeconds(WorkSchedule $schedule, array $stretches): int
    {
        $outside = 0;

        foreach ($stretches as [$from, $to]) {
            $from = CarbonImmutable::instance($from)->utc();
            $to = CarbonImmutable::instance($to)->utc();
            $total = max(0, $to->getTimestamp() - $from->getTimestamp());
            $inside = 0;
            $day = $from->setTimezone($schedule->timezone)->startOfDay()->subDay();
            $lastDay = $to->setTimezone($schedule->timezone)->startOfDay();

            for (; $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
                if (! in_array($day->dayOfWeekIso, $schedule->days ?? [], true)) {
                    continue;
                }

                [$window, $windowEnd] = $this->window($schedule, $day);
                $inside += max(0, min($to->getTimestamp(), $windowEnd->getTimestamp()) - max($from->getTimestamp(), $window->getTimestamp()));
            }

            $outside += max(0, $total - $inside);
        }

        return $outside;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(WorkSchedule $schedule, CarbonImmutable $day): array
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) $schedule->start_time));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) $schedule->end_time));

        $start = $day->setTime($startHour, $startMinute);
        $end = $day->setTime($endHour, $endMinute);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $day->addDay()->setTime($endHour, $endMinute);
        }

        return [$start, $end];
    }
}
