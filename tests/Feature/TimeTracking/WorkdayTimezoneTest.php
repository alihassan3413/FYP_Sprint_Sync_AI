<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\TimeTracking\Actions\RecordActivitySignalAction;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeReview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

/**
 * Everything in a timer session belongs to the workday the session started
 * on, in the workspace's timezone: its work, its paid lunch (and the lunch
 * allowance) and idle time kept as work. Timestamps stay UTC.
 */
final class WorkdayTimezoneTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    private string $timezone = 'Asia/Karachi';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-10-01 08:00:00', $this->timezone)->utc());
    }

    private function team(string $timezone = 'Asia/Karachi'): void
    {
        $this->timezone = $timezone;
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00:00', $timezone)->utc());
        $this->setUpTimeTeam($timezone);
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
    }

    /** Travel to a local wall-clock time in the workspace's timezone. */
    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $this->timezone)->utc());
    }

    /**
     * One shift: start, lunch, back, stop, all in local time.
     */
    private function shift(string $start, string $lunchStart, string $lunchEnd, string $stop): void
    {
        $this->at($start);
        $this->startTimer()->assertOk();
        $this->at($lunchStart);
        $this->timer('lunch-start')->assertOk();
        $this->at($lunchEnd);
        $this->timer('lunch-end')->assertOk();
        $this->at($stop);
        $this->timer('stop')->assertOk();
    }

    /**
     * @return array<string, int>
     */
    private function lunchDays(): array
    {
        return TimeEntry::query()->where('source', TimeEntrySource::Lunch->value)->orderBy('id')->get()
            ->mapWithKeys(fn (TimeEntry $entry) => [$entry->period_start->toDateString() => $entry->paid_seconds])->all();
    }

    private function day(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return app(TimeTotals::class)->breakdown($this->usman(), $day, $day)->toArray();
    }

    public function test_a_lunch_before_noon_in_karachi_belongs_to_that_day(): void
    {
        $this->team();
        $this->shift('2026-10-09 09:00', '2026-10-09 11:00', '2026-10-09 11:45', '2026-10-09 17:00');

        $this->assertSame(['2026-10-09' => 2700], $this->lunchDays());
        $this->assertSame(0, TimeReview::query()->count());
    }

    public function test_a_lunch_after_noon_in_karachi_belongs_to_that_day(): void
    {
        $this->team();
        $this->shift('2026-10-09 09:00', '2026-10-09 13:00', '2026-10-09 14:00', '2026-10-09 18:00');

        $this->assertSame(['2026-10-09' => 3600], $this->lunchDays());
        $this->assertSame(3600, $this->day('2026-10-09')['lunch_paid']);
    }

    public function test_lunches_on_consecutive_days_each_get_the_full_allowance(): void
    {
        $this->team();
        $this->shift('2026-10-08 09:00', '2026-10-08 13:00', '2026-10-08 14:00', '2026-10-08 18:00');
        $this->shift('2026-10-09 09:00', '2026-10-09 11:00', '2026-10-09 12:00', '2026-10-09 18:00');

        $this->assertSame(['2026-10-08' => 3600, '2026-10-09' => 3600], $this->lunchDays());
        $this->assertSame(0, TimeReview::query()->count(), "yesterday's lunch never uses today's allowance");
        $this->assertSame(3600, $this->day('2026-10-09')['lunch_paid']);
    }

    public function test_the_local_date_wins_when_the_utc_date_differs(): void
    {
        $this->team();
        // 04:00 in Karachi on the 9th is still 23:00 UTC on the 8th.
        $this->shift('2026-10-09 04:00', '2026-10-09 04:30', '2026-10-09 05:00', '2026-10-09 08:00');

        $this->assertSame(['2026-10-09' => 1800], $this->lunchDays());
        $this->assertSame(1800, $this->day('2026-10-09')['lunch_paid']);
        $this->assertSame(0, $this->day('2026-10-08')['lunch_paid']);
    }

    public function test_an_overnight_shift_keeps_its_lunch_after_midnight(): void
    {
        $this->team();
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), [1, 2, 3, 4, 5], '18:00', '03:00', null, $this->owner);

        // The reported case: lunch after midnight on the 8th's shift, then before midnight on the 9th's.
        $this->shift('2026-10-08 18:00', '2026-10-09 00:30', '2026-10-09 01:30', '2026-10-09 03:00');
        $this->shift('2026-10-09 18:00', '2026-10-09 23:00', '2026-10-09 23:45', '2026-10-10 03:00');

        $this->assertSame(['2026-10-08' => 3600, '2026-10-09' => 2700], $this->lunchDays());
        $this->assertSame(0, TimeReview::query()->count(), 'no false "lunch past the allowance"');

        $first = $this->day('2026-10-08');
        $this->assertSame(8 * 3600, $first['worked'], '18:00–03:00 is 9h, less the 1h lunch');
        $this->assertSame(3600, $first['lunch_paid']);
        $this->assertSame(9 * 3600, $first['billable'], 'work plus the paid lunch');
        $this->assertSame(0, TimeEntry::query()->where('source', TimeEntrySource::Tracked->value)->sum('outside_schedule_seconds'), 'within the 18:00–03:00 schedule');
    }

    public function test_a_second_lunch_in_the_same_workday_still_shares_the_allowance(): void
    {
        $this->team();
        $this->at('2026-10-08 18:00');
        $this->startTimer();
        $this->at('2026-10-08 22:00');
        $this->timer('lunch-start');
        $this->at('2026-10-08 22:40');
        $this->timer('lunch-end');
        $this->at('2026-10-09 01:00');
        $this->timer('lunch-start');
        $this->at('2026-10-09 01:40');
        $this->timer('lunch-end');
        $this->at('2026-10-09 03:00');
        $this->timer('stop');

        $this->assertSame([2400, 1200], TimeEntry::query()->where('source', TimeEntrySource::Lunch->value)->orderBy('id')->pluck('paid_seconds')->all());
        $review = TimeReview::query()->sole();
        $this->assertSame('2026-10-08', $review->work_date->toDateString());
        $this->assertSame(1200, $review->seconds, '80 minutes of lunch in one workday: 20 past the hour');
    }

    public function test_idle_time_kept_after_midnight_belongs_to_the_workday(): void
    {
        $this->team();
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600, 'idle_enabled' => true, 'idle_seconds' => 300]);
        $this->at('2026-10-08 18:00');
        $session = $this->startTimer()->json('timer.public_id');
        $this->at('2026-10-09 00:35');
        $this->timer('idle-pause', [
            'session' => $session,
            'idle_since' => CarbonImmutable::parse('2026-10-09 00:30', $this->timezone)->toIso8601String(),
            'occurred_at' => CarbonImmutable::parse('2026-10-09 00:35', $this->timezone)->toIso8601String(),
        ])->assertOk();
        $this->at('2026-10-09 00:45');
        $this->timer('resume');
        $this->actingAs($this->member)->postJson(route('workspace.time.idle.keep', [$this->workspace, TimeIdlePeriod::query()->sole()->public_id]), ['reason' => 'Carrier call'])->assertOk();

        $kept = TimeEntry::query()->where('source', TimeEntrySource::IdleKept->value)->sole();
        $this->assertSame('2026-10-08', $kept->period_start->toDateString());
        $this->assertSame('2026-10-08', TimeReview::query()->sole()->work_date->toDateString());
    }

    public function test_dst_change_inside_an_overnight_shift(): void
    {
        $this->team('America/New_York');
        // Clocks go back at 02:00 on 1 Nov 2026; lunch starts in the repeated hour.
        $this->at('2026-10-31 22:00');
        $this->startTimer();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'));
        $this->timer('lunch-start');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 07:30:00', 'UTC'));
        $this->timer('lunch-end');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        $this->timer('stop');

        $this->shift('2026-11-01 22:00', '2026-11-01 23:30', '2026-11-02 00:30', '2026-11-02 04:00');

        $this->assertSame(['2026-10-31' => 3600, '2026-11-01' => 3600], $this->lunchDays());
        $this->assertSame(0, TimeReview::query()->count());
    }

    public function test_weekly_totals_and_invoice_hours_follow_the_workday(): void
    {
        $this->team();
        $this->shift('2026-10-08 18:00', '2026-10-09 00:30', '2026-10-09 01:30', '2026-10-09 03:00');
        $this->shift('2026-10-09 18:00', '2026-10-09 23:00', '2026-10-09 23:45', '2026-10-10 03:00');

        $week = app(TimeTotals::class)->week($this->usman(), CarbonImmutable::parse('2026-10-09'))->toArray();
        $this->assertSame(3600 + 2700, $week['lunch_paid']);
        $this->assertSame(2 * 9 * 3600, $week['billable'], 'two 9h shifts, each with its lunch paid');

        // What a tracked-hours invoice reads for the 8th: that shift's work plus its after-midnight lunch.
        $billable = app(TimeTotals::class)->seconds(new TimeQuery(
            $this->workspace->id,
            personId: $this->usman()->id,
            projectId: $this->project->id,
            from: CarbonImmutable::parse('2026-10-08'),
            to: CarbonImmutable::parse('2026-10-08'),
            billableOnly: true,
        ));
        $this->assertSame(9 * 3600, $billable);
    }

    public function test_the_time_page_shows_the_running_shifts_workday(): void
    {
        $this->team();
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), [1, 2, 3, 4, 5], '18:00', '03:00', null, $this->owner);
        $this->at('2026-10-08 18:00');
        $this->startTimer();
        $this->at('2026-10-09 00:30');
        $this->timer('lunch-start');
        $this->at('2026-10-09 01:00');
        $this->timer('lunch-end');
        $this->at('2026-10-09 01:30');

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('today', '2026-10-09')
                ->where('workday.date', '2026-10-08')
                ->where('workday.is_today', false)
                ->where('workday.timezone_label', 'Asia/Karachi (UTC+5)')
                ->where('workday.overnight_shift', ['start' => '18:00', 'end' => '03:00'])
                ->where('breakdown.today.lunch_paid', 1800));

        $this->at('2026-10-09 10:00');
        $this->timer('stop');
        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('workday.date', '2026-10-09')->where('workday.is_today', true));
    }

    public function test_time_settings_names_the_workspace_timezone(): void
    {
        $this->team();

        $this->actingAs($this->owner)->get(route('workspace.time.settings.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('workspaceTimezone', 'Asia/Karachi (UTC+5)'));
    }

    /* ---- Overnight shifts with several timers ------------------------------ */

    /**
     * @param  list<int>  $days
     */
    private function schedule(array $days, string $start, string $end): void
    {
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), $days, $start, $end, null, $this->owner);
    }

    private function timerRun(string $start, string $stop): void
    {
        $this->at($start);
        $this->startTimer()->assertOk();
        $this->at($stop);
        $this->timer('stop')->assertOk();
    }

    /**
     * @return list<string>
     */
    private function trackedDays(): array
    {
        return TimeEntry::query()->where('source', TimeEntrySource::Tracked->value)->orderBy('id')->get()
            ->map(fn (TimeEntry $entry) => $entry->period_start->toDateString())->all();
    }

    public function test_a_timer_restarted_after_midnight_stays_in_the_overnight_shift(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');

        // The reported sequence: 18:00 start, 23:30 lunch, 00:20 back, 00:30 stop, 00:40 new timer, 02:00 stop.
        $this->shift('2026-10-08 18:00', '2026-10-08 23:30', '2026-10-09 00:20', '2026-10-09 00:30');
        $this->at('2026-10-09 00:40');
        $this->startTimer();

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('workday.date', '2026-10-08')->where('workday.is_today', false));

        $this->at('2026-10-09 02:00');
        $this->timer('stop');

        $this->assertSame(['2026-10-08', '2026-10-08'], $this->trackedDays(), 'two sessions, one workday, never merged into one entry');
        $this->assertSame(['2026-10-08' => 3000], $this->lunchDays());

        $day = $this->day('2026-10-08');
        $this->assertSame((5 * 3600 + 30 * 60 + 10 * 60) + (80 * 60), $day['worked']);
        $this->assertSame(0, $this->day('2026-10-09')['worked']);

        // That evening's shift is a new workday with its own lunch allowance.
        $this->shift('2026-10-09 18:00', '2026-10-09 22:00', '2026-10-09 23:00', '2026-10-10 02:00');
        $this->assertSame(['2026-10-08' => 3000, '2026-10-09' => 3600], $this->lunchDays());
        $this->assertSame(0, TimeReview::query()->count());
    }

    public function test_many_stops_and_starts_in_one_overnight_shift_share_its_workday(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');

        $this->timerRun('2026-10-08 18:00', '2026-10-08 20:00');
        $this->timerRun('2026-10-08 21:00', '2026-10-09 00:15');
        $this->timerRun('2026-10-09 01:00', '2026-10-09 02:30');

        $this->assertSame(['2026-10-08', '2026-10-08', '2026-10-08'], $this->trackedDays());
        $this->assertSame((2 * 3600) + (3 * 3600 + 15 * 60) + (90 * 60), $this->day('2026-10-08')['worked']);
    }

    public function test_a_timer_started_after_the_shift_ends_is_a_new_workday(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');

        $this->timerRun('2026-10-09 02:59', '2026-10-09 03:30');
        $this->timerRun('2026-10-09 03:00', '2026-10-09 04:00');

        $this->assertSame(['2026-10-08', '2026-10-09'], $this->trackedDays(), 'the shift ends at 03:00 exactly');
    }

    public function test_a_daytime_schedule_keeps_the_calendar_day(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5], '09:00', '18:00');

        $this->timerRun('2026-10-09 10:00', '2026-10-09 12:00');
        $this->timerRun('2026-10-09 01:00', '2026-10-09 02:00');

        $this->assertSame(['2026-10-09', '2026-10-09'], $this->trackedDays());
    }

    public function test_without_a_schedule_a_timer_belongs_to_the_day_it_started(): void
    {
        $this->team();

        $this->timerRun('2026-10-08 18:00', '2026-10-09 00:30');
        $this->timerRun('2026-10-09 00:40', '2026-10-09 02:00');

        $this->assertSame(['2026-10-08', '2026-10-09'], $this->trackedDays());
    }

    public function test_a_sunday_night_shift_stays_in_its_week(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5, 6, 7], '18:00', '03:00');

        // Sunday 11 Oct evening, restarted after midnight on Monday the 12th.
        $this->timerRun('2026-10-11 18:00', '2026-10-11 23:00');
        $this->timerRun('2026-10-12 00:30', '2026-10-12 02:00');

        $this->assertSame(['2026-10-11', '2026-10-11'], $this->trackedDays());
        $totals = app(TimeTotals::class);
        $this->assertSame(5 * 3600 + 90 * 60, $totals->week($this->usman(), CarbonImmutable::parse('2026-10-11'))->toArray()['worked'], 'week of 5–11 October');
        $this->assertSame(0, $totals->week($this->usman(), CarbonImmutable::parse('2026-10-12'))->toArray()['worked'], 'the new week starts empty');
    }

    public function test_an_overnight_schedule_across_the_dst_change(): void
    {
        $this->team('America/New_York');
        $this->schedule([1, 2, 3, 4, 5, 6, 7], '22:00', '06:00');

        $this->timerRun('2026-10-31 22:00', '2026-11-01 00:30');
        // 06:30 UTC is 01:30 EST, inside the repeated hour; the shift ends 06:00 EST (11:00 UTC).
        $this->travelTo(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'));
        $this->startTimer();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'));
        $this->timer('stop');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 11:30:00', 'UTC'));
        $this->startTimer();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'));
        $this->timer('stop');

        $this->assertSame(['2026-10-31', '2026-10-31', '2026-11-01'], $this->trackedDays());
    }

    public function test_a_later_schedule_change_never_reclassifies_time(): void
    {
        $this->team();
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');
        $this->timerRun('2026-10-08 18:00', '2026-10-09 00:30');

        // A timer already running when the schedule changes keeps the schedule it started under.
        $this->at('2026-10-09 00:40');
        $this->startTimer();
        $this->at('2026-10-09 01:00');
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->at('2026-10-09 02:00');
        $this->timer('stop');

        $this->assertSame(['2026-10-08', '2026-10-08'], $this->trackedDays());

        // New timers follow the new schedule.
        $this->timerRun('2026-10-10 00:40', '2026-10-10 01:00');
        $this->assertSame(['2026-10-08', '2026-10-08', '2026-10-10'], $this->trackedDays());
    }

    public function test_activity_flags_and_invoice_hours_use_the_resolved_workday(): void
    {
        $this->team();
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600, 'integrity_enabled' => true]);
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');
        $this->timerRun('2026-10-08 18:00', '2026-10-09 00:30');
        $this->at('2026-10-09 00:40');
        $this->startTimer();
        $this->at('2026-10-09 01:30');

        $review = app(RecordActivitySignalAction::class)->handle(
            $this->usman(),
            CarbonImmutable::parse('2026-10-09 01:00', 'Asia/Karachi'),
            CarbonImmutable::parse('2026-10-09 01:30', 'Asia/Karachi'),
            60,
            10_000,
            50,
        );
        $this->assertSame('2026-10-08', $review?->work_date->toDateString());

        $this->at('2026-10-09 02:00');
        $this->timer('stop');

        $billable = app(TimeTotals::class)->seconds(new TimeQuery(
            $this->workspace->id,
            personId: $this->usman()->id,
            projectId: $this->project->id,
            from: CarbonImmutable::parse('2026-10-08'),
            to: CarbonImmutable::parse('2026-10-08'),
            billableOnly: true,
        ));
        $this->assertSame((6 * 3600 + 30 * 60) + (80 * 60), $billable, 'both sessions of the shift are on the 8th');
    }
}
