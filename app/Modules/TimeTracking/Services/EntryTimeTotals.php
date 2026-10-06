<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Services;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeBreakdown;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeHoliday;
use App\Modules\TimeTracking\Models\TimeLeave;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Support\Schedules;
use App\Modules\TimeTracking\Support\TimePolicies;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class EntryTimeTotals implements TimeTotals
{
    private const GROUPS = ['person_id', 'project_id', 'task_id', 'period_start'];

    /**
     * Work counts in full; lunch counts only its paid part (the allowance,
     * plus excess the owner chose to count), so a 74-minute lunch with a
     * 60-minute allowance adds 60 minutes until the owner decides.
     */
    private const SECONDS_SQL = "SUM(CASE WHEN source = 'lunch' THEN COALESCE(paid_seconds, 0) ELSE duration_seconds END)";

    /** Without a schedule, paid leave credits Monday to Friday. */
    private const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    public function __construct(
        private readonly Schedules $schedules,
        private readonly TimePolicies $policies,
    ) {}

    public function seconds(TimeQuery $query): int
    {
        return (int) $this->filtered($query)->toBase()->selectRaw(self::SECONDS_SQL.' as total')->value('total');
    }

    public function grouped(TimeQuery $query, array $by): array
    {
        if ($by === [] || array_diff($by, self::GROUPS) !== []) {
            throw new InvalidArgumentException('Group time by person_id, project_id, task_id or period_start.');
        }

        return $this->filtered($query)
            ->select($by)
            ->selectRaw(self::SECONDS_SQL.' as seconds')
            ->groupBy($by)
            ->orderBy($by[0])
            ->toBase()
            ->get()
            ->map(function (object $row) {
                $row = (array) $row;
                $row['seconds'] = (int) $row['seconds'];

                if (isset($row['period_start'])) {
                    $row['period_start'] = substr((string) $row['period_start'], 0, 10);
                }

                return $row;
            })
            ->all();
    }

    public function breakdown(Person $person, CarbonImmutable $from, CarbonImmutable $to): TimeBreakdown
    {
        $work = new TimeQuery($person->workspace_id, personId: $person->id, from: $from, to: $to);
        $holidays = $this->holidays($person, $from, $to);

        /* The paid part of each lunch, plus excess the owner counted (its own entries). */
        $lunchPaid = (int) $this->entries($person, $from, $to)->where('source', TimeEntrySource::Lunch->value)->sum('paid_seconds');

        [$paidLeave, $unpaidDays] = $this->leave($person, $from, $to, array_keys($holidays));

        return new TimeBreakdown(
            worked: $this->seconds($work),
            billable: $this->seconds(new TimeQuery($person->workspace_id, personId: $person->id, from: $from, to: $to, billableOnly: true)),
            lunchPaid: $lunchPaid,
            lunchExcessOpen: (int) $this->reviews($person, $from, $to)->where('status', 'open')->sum('seconds'),
            holiday: array_sum($holidays),
            paidLeave: $paidLeave,
            unpaidLeaveDays: $unpaidDays,
            outsideSchedule: (int) $this->entries($person, $from, $to)->where('source', TimeEntrySource::Tracked->value)->sum('outside_schedule_seconds'),
            workedOnHoliday: $holidays === [] ? 0 : (int) $this->filtered($work)
                ->where(function (Builder $q) use ($holidays) {
                    foreach (array_keys($holidays) as $date) {
                        $q->orWhereDate('period_start', $date);
                    }
                })
                ->sum('duration_seconds'),
        );
    }

    public function openActivityReviews(int $workspaceId, array $personIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return TimeReview::query()->open()
            ->where('workspace_id', $workspaceId)
            ->where('kind', ReviewKind::Activity->value)
            ->whereIn('person_id', $personIds ?: [0])
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->count();
    }

    public function week(Person $person, CarbonImmutable $day): TimeBreakdown
    {
        $start = $day->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $timezone = $person->workspace->timezone;
        /* The target in force at the end of the week (or now, for the week in progress). */
        $end = CarbonImmutable::parse($start->addDays(6)->toDateString(), $timezone)->endOfDay();
        $policy = $this->policies->at($person->workspace_id, $end->lessThan(CarbonImmutable::now()) ? $end : CarbonImmutable::now());

        return $this->breakdown($person, $start, $start->addDays(6))->withTarget($policy->weeklyTargetSeconds);
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filtered(TimeQuery $query): Builder
    {
        return TimeEntry::query()
            ->active()
            ->where('workspace_id', $query->workspaceId)
            /* Billable totals also take paid lunch (only its paid part, see SECONDS_SQL). */
            ->whereIn('source', $query->billableOnly ? [...TimeEntrySource::WORK, TimeEntrySource::Lunch->value] : TimeEntrySource::WORK)
            ->when($query->personId, fn (Builder $q) => $q->where('person_id', $query->personId))
            ->when($query->projectId, fn (Builder $q) => $q->where('project_id', $query->projectId))
            ->when($query->projectIds !== null, fn (Builder $q) => $q->whereIn('project_id', $query->projectIds ?: [0]))
            ->when($query->taskId, fn (Builder $q) => $q->where('task_id', $query->taskId))
            ->when($query->from, fn (Builder $q) => $q->whereDate('period_start', '>=', $query->from->toDateString()))
            ->when($query->to, fn (Builder $q) => $q->whereDate('period_start', '<=', $query->to->toDateString()))
            ->when($query->billableOnly, fn (Builder $q) => $q->where('billable', true));
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function entries(Person $person, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TimeEntry::query()->active()->where('person_id', $person->id)
            ->whereDate('period_start', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString());
    }

    /**
     * @return Builder<TimeReview>
     */
    private function reviews(Person $person, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TimeReview::query()->where('person_id', $person->id)->where('kind', ReviewKind::LunchExcess->value)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString());
    }

    /**
     * Holiday credit per date for this person (everyone's holidays, and ones they're listed on).
     *
     * @return array<string, int>
     */
    private function holidays(Person $person, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $credits = [];

        TimeHoliday::query()->active()
            ->where('workspace_id', $person->workspace_id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where(fn (Builder $q) => $q->whereDoesntHave('people')->orWhereHas('people', fn (Builder $people) => $people->where('people.id', $person->id)))
            ->get()
            ->each(function (TimeHoliday $holiday) use (&$credits) {
                $date = $holiday->date->toDateString();
                $credits[$date] = max($credits[$date] ?? 0, $holiday->credit_seconds);
            });

        return $credits;
    }

    /**
     * Paid leave credit on working days in the range (not on holidays, which
     * are already credited), and the number of unpaid leave days.
     *
     * @param  list<string>  $holidayDates
     * @return array{0: int, 1: int}
     */
    private function leave(Person $person, CarbonImmutable $from, CarbonImmutable $to, array $holidayDates): array
    {
        $paid = 0;
        $unpaidDays = 0;

        $leaves = TimeLeave::query()->active()->where('person_id', $person->id)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        foreach ($leaves as $leave) {
            $start = CarbonImmutable::parse(max($leave->start_date->toDateString(), $from->toDateString()));
            $end = CarbonImmutable::parse(min($leave->end_date->toDateString(), $to->toDateString()));

            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $schedule = $this->schedules->for($person, CarbonImmutable::parse($day->toDateString(), $person->workspace->timezone)->endOfDay());
                $workDays = $schedule?->days ?? self::DEFAULT_WORK_DAYS;

                if (! in_array($day->dayOfWeekIso, $workDays, true) || in_array($day->toDateString(), $holidayDates, true)) {
                    continue;
                }

                $leave->type === LeaveType::Paid ? $paid += $leave->credit_seconds_per_day : $unpaidDays++;
            }
        }

        return [$paid, $unpaidDays];
    }
}
