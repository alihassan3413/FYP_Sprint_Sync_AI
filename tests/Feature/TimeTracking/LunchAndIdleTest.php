<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\TimeTracking\Actions\ResolveTimeReviewAction;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

/**
 * Paid lunch (a paid break: paid time, not work, not billable) and automatic
 * idle pauses from SprintSync Desktop.
 */
final class LunchAndIdleTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 08:00:00');
        $this->setUpTimeTeam();
    }

    private function at(string $time): void
    {
        $this->travelTo("2026-10-12 {$time}");
    }

    private function iso(string $time): string
    {
        return CarbonImmutable::parse("2026-10-12 {$time}")->toIso8601String();
    }

    private function begin(string $time = '09:00:00'): void
    {
        $this->at($time);
        $this->session = $this->startTimer()->json('timer.public_id');
    }

    private function idle(string $since, string $detected, ?string $key = null): TestResponse
    {
        return $this->timer('idle-pause', ['session' => $this->session, 'idle_since' => $this->iso($since), 'occurred_at' => $this->iso($detected), ...($key ? ['operation_key' => $key] : [])]);
    }

    private function day(): array
    {
        return app(TimeTotals::class)->breakdown($this->usman(), CarbonImmutable::parse('2026-10-12'), CarbonImmutable::parse('2026-10-12'))->toArray();
    }

    /* ---- Lunch ----------------------------------------------------------- */

    public function test_paid_lunch_is_paid_and_billable_but_not_worked(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin('09:00:00');
        $this->at('13:00:00');
        $this->timer('lunch-start')->assertOk()->assertJsonPath('timer.state', 'lunch');
        $this->at('14:00:00');
        $this->timer('lunch-end')->assertOk()->assertJsonPath('timer.state', 'running');
        $this->at('17:00:00');
        $this->timer('stop');

        $day = $this->day();
        $this->assertSame(25200, $day['worked'], '7h of active work');
        $this->assertSame(3600, $day['lunch_paid'], 'still its own category');
        $this->assertSame(28800, $day['compensated'], '8h paid');
        $this->assertSame(28800, $day['billable'], '8h billable: work plus the allowed lunch');
        $this->assertSame(0, $day['lunch_excess_open']);

        $lunch = TimeEntry::query()->where('source', TimeEntrySource::Lunch->value)->sole();
        $this->assertTrue($lunch->billable);
        $this->assertSame(3600, $lunch->paid_seconds);
        $this->assertSame(0, TimeReview::query()->count(), 'exactly the allowance needs no review');
    }

    public function test_lunch_on_a_non_billable_timer_is_paid_but_not_billable(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->at('09:00:00');
        $this->startTimer(['billable' => false]);
        $this->at('12:00:00');
        $this->timer('lunch-start');
        $this->at('13:00:00');
        $this->timer('stop');

        $this->assertSame(0, $this->day()['billable']);
        $this->assertSame(3600, $this->day()['lunch_paid']);
    }

    private function longLunch(): TimeReview
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin();
        $this->at('13:00:00');
        $this->timer('lunch-start');
        $this->at('14:14:00');
        $this->timer('lunch-end');
        $this->at('17:00:00');
        $this->timer('stop');

        return TimeReview::query()->sole();
    }

    public function test_a_long_lunch_counts_only_the_allowance_until_the_owner_decides(): void
    {
        $review = $this->longLunch();

        $day = $this->day();
        $this->assertSame(3600, $day['lunch_paid'], '60m paid');
        $this->assertSame(840, $day['lunch_excess_open'], '14m waiting for the owner');
        $this->assertSame(4 * 3600 + 9960 + 3600, $day['billable'], 'work plus 60m of lunch, not the extra 14m');

        $this->assertSame(ReviewKind::LunchExcess, $review->kind);
        $this->assertSame(840, $review->seconds);
        $this->assertSame(4440, $review->details['lunch_seconds']);
        $this->assertSame('Lunch ran 14m past the paid allowance.', $review->summary);
        $this->assertSame(['counted', 'not_counted'], $review->kind->decisions());
    }

    public function test_the_owner_can_leave_the_excess_uncounted(): void
    {
        $review = $this->longLunch();
        $billable = $this->day()['billable'];

        app(ResolveTimeReviewAction::class)->handle($review, 'not_counted', null, $this->owner);

        $this->assertSame($billable, $this->day()['billable'], 'still only 60m of lunch');
        $this->assertSame(3600, $this->day()['lunch_paid']);
        $this->assertSame(0, $this->day()['lunch_excess_open']);
        $this->assertSame(1, AuditLog::query()->where('action', 'time.review_resolved')->count());
    }

    public function test_the_owner_can_count_the_excess_as_paid_and_billable(): void
    {
        $review = $this->longLunch();
        $billable = $this->day()['billable'];

        app(ResolveTimeReviewAction::class)->handle($review, 'counted', 'Doctor appointment', $this->owner);
        app(ResolveTimeReviewAction::class)->handle($review->fresh(), 'counted', 'again', $this->owner);

        $this->assertSame($billable + 840, $this->day()['billable']);
        $this->assertSame(4440, $this->day()['lunch_paid']);
        $this->assertSame(2, TimeEntry::query()->where('source', 'lunch')->count(), 'the lunch and one counted-excess entry (decided once)');
        $this->assertSame(4440, TimeEntry::query()->where('source', 'lunch')->whereNull('adjusts_entry_id')->sole()->duration_seconds, 'the original lunch is never edited');
    }

    public function test_a_normal_pause_is_neither_paid_nor_billable(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin();
        $this->at('12:00:00');
        $this->timer('pause');
        $this->at('13:00:00');
        $this->timer('resume');
        $this->at('17:00:00');
        $this->timer('stop');

        $day = $this->day();
        $this->assertSame(25200, $day['worked']);
        $this->assertSame(25200, $day['billable']);
        $this->assertSame(25200, $day['compensated']);
    }

    public function test_stopping_during_lunch_ends_lunch_first(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin();
        $this->at('12:00:00');
        $this->timer('lunch-start');
        $this->at('12:30:00');
        $this->timer('stop')->assertOk();

        $this->assertSame(10800, $this->day()['worked']);
        $this->assertSame(1800, $this->day()['lunch_paid']);
    }

    public function test_lunch_needs_the_policy_and_a_running_timer(): void
    {
        $this->begin();
        $this->timer('lunch-start')->assertUnprocessable();

        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->timer('pause');
        $this->timer('lunch-start')->assertStatus(409);
        $this->assertSame(TimerState::Paused, TimeSession::query()->sole()->state);
    }

    public function test_lunch_changes_are_safe_to_retry_and_sync_late(): void
    {
        $this->policy(['lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin();
        $start = $this->key();
        $end = $this->key();

        /* Offline at 13:00 and 13:45, synced at 15:00, each sent twice. */
        $this->at('15:00:00');
        foreach ([1, 2] as $attempt) {
            $this->timer('lunch-start', ['operation_key' => $start, 'session' => $this->session, 'occurred_at' => $this->iso('13:00:00')])->assertOk();
        }
        foreach ([1, 2] as $attempt) {
            $this->timer('lunch-end', ['operation_key' => $end, 'session' => $this->session, 'occurred_at' => $this->iso('13:45:00')])->assertOk();
        }
        $this->timer('stop');

        $this->assertSame(1, TimeEntry::query()->where('source', 'lunch')->count());
        $this->assertSame(2700, $this->day()['lunch_paid']);
        $this->assertSame(4 * 3600 + 3600 + 900, $this->day()['worked'], '09–13 and 13:45–15:00');
    }

    /* ---- Idle -------------------------------------------------------------- */

    public function test_idle_detection_off_means_no_idle_pause(): void
    {
        $this->begin();

        $this->idle('09:30:00', '09:35:00')->assertUnprocessable();
        $this->assertSame(TimerState::Running, TimeSession::query()->sole()->state);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function thresholds(): array
    {
        return [
            '2 minutes' => [120, '10:00:00', '10:02:00'],
            '5 minutes' => [300, '10:00:00', '10:05:00'],
            '15 minutes' => [900, '10:00:00', '10:15:00'],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_work_stops_counting_at_the_last_activity(int $threshold, string $since, string $detected): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => $threshold]);
        $this->begin();
        $this->at($detected);

        $this->idle($since, $detected)
            ->assertOk()
            ->assertJsonPath('timer.state', 'paused')
            ->assertJsonPath('timer.pause_reason', 'idle')
            ->assertJsonPath('timer.accumulated_seconds', 3600);

        $period = TimeIdlePeriod::query()->sole();
        $this->assertSame('2026-10-12 10:00:00', $period->started_at->toDateTimeString());
        $this->assertSame($threshold, $period->threshold_seconds);

        /* Back at 10:20: the idle time is not work unless kept. */
        $this->at('10:20:00');
        $this->timer('resume');
        $this->at('11:00:00');
        $this->timer('stop');

        $this->assertSame(3600 + 2400, $this->day()['worked']);
        $this->assertSame('2026-10-12 10:20:00', $period->fresh()->ended_at->toDateTimeString());
    }

    public function test_the_threshold_comes_from_the_server(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $this->at('10:02:00');

        /* A client claiming a shorter idle time than the workspace allows is refused. */
        $this->idle('10:00:00', '10:02:00')->assertUnprocessable();
        $this->assertSame(TimerState::Running, TimeSession::query()->sole()->state);
    }

    public function test_the_policy_at_the_time_of_the_idle_decides(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 600]);
        $this->begin();
        $this->at('12:00:00');
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 120]);

        /* Synced late: idle from 11:00, detected 11:02 under the 10-minute rule then in force. */
        $this->idle('11:00:00', '11:02:00')->assertUnprocessable();
        $this->idle('11:00:00', '11:10:00')->assertOk();
    }

    public function test_an_offline_idle_pause_syncs_once(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $key = $this->key();
        $this->at('12:00:00');

        $this->idle('10:00:00', '10:05:00', $key)->assertOk();
        $this->idle('10:00:00', '10:05:00', $key)->assertOk();

        $this->assertSame(1, TimeIdlePeriod::query()->count());
        $this->assertSame(3600, TimeSession::query()->sole()->accumulated_seconds);
    }

    public function test_idle_never_interrupts_lunch(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300, 'lunch_enabled' => true, 'lunch_allowance_seconds' => 3600]);
        $this->begin();
        $this->at('13:00:00');
        $this->timer('lunch-start');
        $this->at('13:30:00');

        $this->idle('13:00:00', '13:30:00')->assertOk()->assertJsonPath('timer.state', 'lunch');
        $this->assertSame(0, TimeIdlePeriod::query()->count());
    }

    public function test_idle_time_can_be_kept_as_work_with_a_reason(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $this->at('10:05:00');
        $this->idle('10:00:00', '10:05:00');
        $this->at('10:13:00');
        $this->timer('resume');
        $period = TimeIdlePeriod::query()->sole();

        $keep = fn (array $data) => $this->actingAs($this->member)->postJson(route('workspace.time.idle.keep', [$this->workspace, $period->public_id]), $data);
        $keep([])->assertJsonValidationErrors('reason');
        $keep(['reason' => 'Client phone call'])->assertOk()->assertJsonPath('decision', 'kept');
        $keep(['reason' => 'Again'])->assertOk();

        $kept = TimeEntry::query()->where('source', TimeEntrySource::IdleKept->value)->sole();
        $this->assertSame(780, $kept->duration_seconds, '13 minutes');
        $this->assertSame('Client phone call', $kept->note);
        $this->assertSame('kept', $period->fresh()->decision, 'the detection itself stays on record');

        $this->at('11:00:00');
        $this->timer('stop');
        $this->assertSame(3600 + 2820 + 780, $this->day()['worked']);

        $review = TimeReview::query()->sole();
        $this->assertSame(ReviewKind::IdleKept, $review->kind);
    }

    public function test_the_owner_can_reject_kept_idle_time(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $this->at('10:05:00');
        $this->idle('10:00:00', '10:05:00');

        /* Keeping it while still paused resumes the timer now. */
        $this->at('10:13:00');
        $this->actingAs($this->member)->postJson(route('workspace.time.idle.keep', [$this->workspace, TimeIdlePeriod::query()->sole()->public_id]), ['reason' => 'Reading printed contract'])->assertOk();
        $this->assertSame(TimerState::Running, TimeSession::query()->sole()->state);

        $this->actingAs($this->owner)->post(route('workspace.time.reviews.resolve', [$this->workspace, TimeReview::query()->sole()->public_id]), ['decision' => 'rejected', 'note' => 'Not agreed'])->assertSessionHasNoErrors();

        $this->assertNotNull(TimeEntry::query()->where('source', 'idle_kept')->sole()->voided_at);
        $this->assertSame('resolved', TimeReview::query()->sole()->status);
    }

    public function test_dropping_idle_time_adds_nothing(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $this->at('10:05:00');
        $this->idle('10:00:00', '10:05:00');
        $this->at('10:10:00');
        $this->timer('resume');

        $this->actingAs($this->member)->postJson(route('workspace.time.idle.drop', [$this->workspace, TimeIdlePeriod::query()->sole()->public_id]))->assertOk()->assertJsonPath('decision', 'discarded');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_people_only_decide_their_own_idle_time(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);
        $this->begin();
        $this->at('10:05:00');
        $this->idle('10:00:00', '10:05:00');

        $this->actingAs($this->owner)->postJson(route('workspace.time.idle.keep', [$this->workspace, TimeIdlePeriod::query()->sole()->public_id]), ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($this->member)->post(route('workspace.time.reviews.resolve', [$this->workspace, 'nope']), ['decision' => 'paid'])->assertForbidden();
    }
}
