<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\TimeTracking\Actions\AddHistoricalHoursAction;
use App\Modules\TimeTracking\Actions\ManageTimeOffAction;
use App\Modules\TimeTracking\Actions\RecordActivitySignalAction;
use App\Modules\TimeTracking\Actions\ResolveTimeReviewAction;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeHoliday;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Support\ActivityPattern;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

/**
 * Schedules, weekly targets, holidays, leave and activity integrity: reported
 * next to real work, never instead of it, and never as client time.
 */
final class WorkTimeReportingTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 07:00:00');
        $this->setUpTimeTeam();
    }

    /** Monday 5 Oct 2026 … Sunday 11 Oct. */
    private function week(): array
    {
        return app(TimeTotals::class)->week($this->usman(), CarbonImmutable::parse('2026-10-07'))->toArray();
    }

    private function shift(string $start, string $end, string $timezone = 'UTC'): void
    {
        $this->travelTo(CarbonImmutable::parse($start, $timezone)->utc());
        $this->startTimer();
        $this->travelTo(CarbonImmutable::parse($end, $timezone)->utc());
        $this->timer('stop');
    }

    private function schedule(array $days, string $start, string $end, ?string $timezone = null): void
    {
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), $days, $start, $end, $timezone, $this->owner);
    }

    /* ---- Schedules ------------------------------------------------------- */

    public function test_work_outside_a_9_to_5_schedule_is_reported_not_blocked(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');

        $this->shift('2026-10-05 08:40:00', '2026-10-05 18:00:00');

        $entry = TimeEntry::query()->sole();
        $this->assertSame(33600, $entry->duration_seconds, 'all 9h 20m counts as work');
        $this->assertSame(4800, $entry->outside_schedule_seconds, '20m before 9 and 1h after 5');
        $this->assertSame(4800, $this->week()['outside_schedule']);
    }

    public function test_an_overnight_shift_in_lahore(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);
        $this->schedule([1, 2, 3, 4, 5], '18:00', '02:00');

        /* Monday 18:05 → Tuesday 02:30 Lahore time. */
        $this->shift('2026-10-05 18:05:00', '2026-10-06 02:30:00', 'Asia/Karachi');

        $entry = TimeEntry::query()->sole();
        $this->assertSame(30300, $entry->duration_seconds);
        $this->assertSame(1800, $entry->outside_schedule_seconds, 'only the half hour after 02:00');
        $this->assertSame('2026-10-05', $entry->period_start->toDateString());
    }

    public function test_schedules_follow_real_clock_time_across_daylight_saving(): void
    {
        $this->workspace->update(['timezone' => 'America/New_York']);
        $this->schedule([7], '00:00', '04:00', 'America/New_York');

        /* Sunday Nov 1, 2026: clocks go back at 2am. 00:00–04:00 local is 5 real hours. */
        $this->travelTo('2026-11-01 03:00:00');
        $this->shift('2026-11-01 00:00:00', '2026-11-01 04:00:00', 'America/New_York');

        $entry = TimeEntry::query()->sole();
        $this->assertSame(18000, $entry->duration_seconds);
        $this->assertSame(0, $entry->outside_schedule_seconds);
    }

    public function test_a_schedule_never_creates_hours(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');

        $this->assertSame(0, $this->week()['worked']);
        $this->assertSame(0, TimeEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_SCHEDULE_CHANGED->value)->count());
    }

    /* ---- Weekly target --------------------------------------------------- */

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function weeks(): array
    {
        return [
            'under 40' => [38 * 3600, 0],
            'exactly 40' => [40 * 3600, 0],
            'over 40' => [43 * 3600 + 900, 3 * 3600 + 900],
        ];
    }

    #[DataProvider('weeks')]
    public function test_time_over_the_weekly_target_is_tracked_separately(int $worked, int $over): void
    {
        $this->policy(['weekly_target_seconds' => 40 * 3600]);
        app(AddHistoricalHoursAction::class)->handle($this->workspace, $this->project, null, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-05'), 'Test', true, [['person' => $this->usman(), 'seconds' => min($worked, 86400)]], $this->key(), $this->owner);
        if ($worked > 86400) {
            $this->travelTo('2026-10-07 08:00:00');
            app(AddHistoricalHoursAction::class)->handle($this->workspace, $this->project, null, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), 'Test', true, [['person' => $this->usman(), 'seconds' => $worked - 86400]], $this->key(), $this->owner);
        }

        $week = $this->week();
        $this->assertSame($worked, $week['worked'], 'every minute of work is kept');
        $this->assertSame(144000, $week['target']);
        $this->assertSame($over, $week['over_target']);
    }

    public function test_the_timer_keeps_running_past_the_target(): void
    {
        $this->policy(['weekly_target_seconds' => 3600]);
        $this->shift('2026-10-05 09:00:00', '2026-10-05 11:00:00');

        $this->startTimer()->assertOk();
        $this->assertSame(3600, $this->week()['over_target']);
    }

    /* ---- Holidays and leave ---------------------------------------------- */

    public function test_a_holiday_for_everyone_is_paid_credit_not_tracked_or_billable(): void
    {
        $this->travelTo('2026-10-09 08:00:00');
        $this->actingAs($this->owner)->post(route('workspace.time.settings.time-off', $this->workspace), [
            'kind' => 'holiday', 'name' => 'Founders Day', 'start_date' => '2026-10-08', 'hours' => '8', 'idempotency_key' => $this->key(),
        ])->assertSessionHasNoErrors();

        $week = $this->week();
        $this->assertSame(28800, $week['holiday']);
        $this->assertSame(0, $week['worked']);
        $this->assertSame(0, $week['billable']);
        $this->assertSame(28800, $week['compensated']);
        $this->assertSame(0, TimeEntry::query()->count(), 'no invented timer entries');
        $this->assertSame(28800, app(TimeTotals::class)->week($this->team['Faheem'], CarbonImmutable::parse('2026-10-07'))->toArray()['holiday']);
    }

    public function test_a_holiday_for_selected_people_only(): void
    {
        app(ManageTimeOffAction::class)->addHoliday($this->workspace, CarbonImmutable::parse('2026-10-06'), 'Team day', 28800, [$this->usman()], $this->owner);

        $this->assertSame(28800, $this->week()['holiday']);
        $this->assertSame(0, app(TimeTotals::class)->week($this->team['Faheem'], CarbonImmutable::parse('2026-10-07'))->toArray()['holiday']);
    }

    public function test_working_on_a_holiday_shows_both(): void
    {
        app(ManageTimeOffAction::class)->addHoliday($this->workspace, CarbonImmutable::parse('2026-10-06'), 'Holiday', 28800, [], $this->owner);
        $this->shift('2026-10-06 10:00:00', '2026-10-06 14:00:00');

        $week = $this->week();
        $this->assertSame(28800, $week['holiday']);
        $this->assertSame(14400, $week['worked']);
        $this->assertSame(14400, $week['worked_on_holiday']);
        $this->assertSame(43200, $week['compensated']);
        $this->assertSame(14400, $week['billable'], 'only the real work is billable');
    }

    public function test_paid_leave_counts_toward_paid_time_and_unpaid_leave_does_not(): void
    {
        $this->policy(['weekly_target_seconds' => 40 * 3600]);
        app(AddHistoricalHoursAction::class)->handle($this->workspace, $this->project, null, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-05'), 'Test', true, [['person' => $this->usman(), 'seconds' => 32 * 3600 > 86400 ? 86400 : 32 * 3600]], $this->key(), $this->owner);
        $this->travelTo('2026-10-08 08:00:00');
        app(AddHistoricalHoursAction::class)->handle($this->workspace, $this->project, null, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), 'Test', true, [['person' => $this->usman(), 'seconds' => 32 * 3600 - 86400]], $this->key(), $this->owner);

        $time = app(ManageTimeOffAction::class);
        $time->addLeave($this->usman(), CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-11'), LeaveType::Paid, 28800, null, $this->key(), $this->owner);
        $time->addLeave($this->team['Faheem'], CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09'), LeaveType::Unpaid, 28800, null, $this->key(), $this->owner);

        $week = $this->week();
        $this->assertSame(28800, $week['paid_leave'], 'only Friday is a working day; the weekend is not credited');
        $this->assertSame(32 * 3600, $week['billable']);
        $this->assertSame(40 * 3600, $week['compensated']);
        $this->assertSame(0, $week['over_target']);

        $faheem = app(TimeTotals::class)->week($this->team['Faheem'], CarbonImmutable::parse('2026-10-07'))->toArray();
        $this->assertSame(0, $faheem['paid_leave']);
        $this->assertSame(1, $faheem['unpaid_leave_days']);
        $this->assertSame(0, $faheem['compensated']);
    }

    public function test_leave_is_retry_safe_and_removable(): void
    {
        $key = $this->key();
        $time = app(ManageTimeOffAction::class);
        $leave = $time->addLeave($this->usman(), CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09'), LeaveType::Paid, 28800, null, $key, $this->owner);
        $time->addLeave($this->usman(), CarbonImmutable::parse('2026-10-09'), CarbonImmutable::parse('2026-10-09'), LeaveType::Paid, 28800, null, $key, $this->owner);
        $this->assertSame(28800, $this->week()['paid_leave']);

        $this->actingAs($this->owner)->delete(route('workspace.time.settings.leaves.destroy', [$this->workspace, $leave->public_id]))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->week()['paid_leave']);
    }

    public function test_the_same_holiday_is_not_added_twice(): void
    {
        $time = app(ManageTimeOffAction::class);
        $time->addHoliday($this->workspace, CarbonImmutable::parse('2026-12-25'), 'Christmas Day', 28800, [], $this->owner);
        $time->addHoliday($this->workspace, CarbonImmutable::parse('2026-12-25'), 'Christmas Day', 28800, [], $this->owner);

        $this->assertSame(1, TimeHoliday::query()->count());
    }

    /* ---- Activity integrity --------------------------------------------------- */

    public function test_activity_signals_are_ignored_while_integrity_is_off(): void
    {
        $this->assertNull(app(RecordActivitySignalAction::class)->handle($this->usman(), CarbonImmutable::now()->subMinutes(20), CarbonImmutable::now(), 40, 30000, 50));
        $this->assertSame(0, TimeReview::query()->count());
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: bool}>
     */
    public static function patterns(): array
    {
        return [
            'jiggler: every 30s, 0.5% variation' => [40, 30000, 50, true],
            'human typing: bursts' => [400, 1500, 9000, false],
            'reading: few resets' => [6, 200000, 4000, false],
            'regular but too few' => [10, 30000, 50, false],
            'meeting: almost no activity' => [1, 0, 0, false],
            'repetitive workflow, still human variation' => [60, 20000, 1500, false],
        ];
    }

    #[DataProvider('patterns')]
    public function test_only_clearly_machine_like_patterns_are_flagged(int $resets, int $gap, int $variation, bool $flagged): void
    {
        $this->policy(['integrity_enabled' => true]);

        $review = app(RecordActivitySignalAction::class)->handle($this->usman(), CarbonImmutable::now()->subMinutes(20), CarbonImmutable::now(), $resets, $gap, $variation);

        $this->assertSame($flagged, $review !== null);
        $this->assertSame($flagged ? 1 : 0, TimeReview::query()->count(), 'ordinary windows leave nothing behind');
    }

    public function test_a_flag_is_a_review_never_a_verdict_and_is_recorded_once(): void
    {
        $this->policy(['integrity_enabled' => true]);
        $start = CarbonImmutable::now()->subMinutes(20);

        $this->actingAs($this->member)->postJson(route('desktop.time.activity', $this->workspace), [])->assertUnauthorized();
        foreach ([1, 2] as $attempt) {
            app(RecordActivitySignalAction::class)->handle($this->usman(), $start, $start->addMinutes(20), 40, 30000, 50);
        }

        $review = TimeReview::query()->sole();
        $this->assertSame(ReviewKind::Activity, $review->kind);
        $this->assertSame('Activity appears unusually repetitive. Review recommended.', $review->summary);
        $this->assertStringNotContainsStringIgnoringCase('cheat', json_encode($review->toArray()));
        $this->assertStringNotContainsStringIgnoringCase('bot', $review->summary);
        $this->assertSame(['window_start', 'window_end', 'resets', 'mean_gap_seconds', 'variation_percent', 'confidence'], array_keys($review->details), 'only summary numbers, nothing about keys, apps or sites');

        $totals = app(TimeTotals::class);
        $this->assertSame(1, $totals->openActivityReviews($this->workspace->id, [$this->usman()->id], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
        app(ResolveTimeReviewAction::class)->handle($review, 'no_concern', 'Long video call', $this->owner);
        $this->assertSame(0, $totals->openActivityReviews($this->workspace->id, [$this->usman()->id], CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
    }

    public function test_the_thresholds_are_conservative(): void
    {
        $this->assertNull(ActivityPattern::evaluate(19, 30000, 0));
        $this->assertNull(ActivityPattern::evaluate(40, 4000, 0), 'fast bursts are human');
        $this->assertNull(ActivityPattern::evaluate(40, 30000, 301));
        $this->assertSame('medium', ActivityPattern::evaluate(20, 30000, 300)['confidence']);
        $this->assertSame('high', ActivityPattern::evaluate(40, 30000, 100)['confidence']);
    }

    /* ---- Owner view ---------------------------------------------------------- */

    public function test_the_owner_sees_the_team_week_and_open_reviews(): void
    {
        $this->policy(['weekly_target_seconds' => 3600, 'integrity_enabled' => true]);
        $this->shift('2026-10-05 09:00:00', '2026-10-05 11:00:00');
        app(RecordActivitySignalAction::class)->handle($this->usman(), CarbonImmutable::now()->subMinutes(20), CarbonImmutable::now(), 40, 30000, 50);

        $this->actingAs($this->owner)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->has('team', 6)
                ->where('team.5.name', 'Muhammad Usman Ghani')
                ->where('team.5.worked', 7200)
                ->where('team.5.over_target', 3600)
                ->where('reviews.0.kind', 'activity')
                ->where('reviews.0.decisions', ['no_concern', 'follow_up']));

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('team', [])->where('reviews', [])->where('breakdown.week.over_target', 3600));
    }

    public function test_totals_never_mix_categories(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        app(ManageTimeOffAction::class)->addHoliday($this->workspace, CarbonImmutable::parse('2026-10-09'), 'Holiday', 28800, [], $this->owner);
        $this->travelTo('2026-10-05 09:00:00');
        $this->startTimer();
        $this->travelTo('2026-10-05 13:00:00');
        $this->timer('lunch-start');
        $this->travelTo('2026-10-05 14:00:00');
        $this->timer('lunch-end');
        $this->travelTo('2026-10-05 17:00:00');
        $this->timer('stop');

        $week = $this->week();
        $this->assertSame(25200, $week['worked']);
        $this->assertSame(28800, $week['billable'], 'work plus allowed lunch; never the holiday');
        $this->assertSame(3600, $week['lunch_paid']);
        $this->assertSame(28800, $week['holiday']);
        $this->assertSame(25200 + 3600 + 28800, $week['compensated']);
        $this->assertSame(25200, app(TimeTotals::class)->seconds(new TimeQuery($this->workspace->id, personId: $this->usman()->id)), 'worked time never includes lunch');
        $this->assertSame(28800, app(TimeTotals::class)->seconds(new TimeQuery($this->workspace->id, personId: $this->usman()->id, billableOnly: true)), 'billable time does');
    }
}
