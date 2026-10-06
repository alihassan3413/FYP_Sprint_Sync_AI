<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\TimeTracking\Http\Requests\TimerOperationRequest;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * Changes the desktop app queued while offline: they count from when the
 * person pressed the button (occurred_at), within limits, in order, once.
 */
final class OfflineTimerTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->setUpTimeTeam();
        $this->session = $this->startTimer()->json('timer.public_id');
    }

    private function at(string $time): string
    {
        return CarbonImmutable::parse("2026-10-12 {$time}")->toIso8601String();
    }

    public function test_a_stop_pressed_offline_ends_when_it_was_pressed(): void
    {
        /* Offline at 13:00, Stop pressed at 14:00, back online at 15:00. */
        $this->travelTo('2026-10-12 15:00:00');

        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('14:00')])->assertOk();

        $entry = TimeEntry::query()->sole();
        $this->assertSame(7200, $entry->duration_seconds, '12:00 → 14:00, not 15:00');
        $this->assertSame('2026-10-12 14:00:00', $entry->ended_at->toDateTimeString());
        $this->assertSame('2026-10-12 15:00:00', $entry->created_at->toDateTimeString(), 'arrival time is kept separately');
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::TIME_TIMER_STOPPED->value)->sole()->metadata['synced_late']);
    }

    public function test_queued_pause_then_stop_replay_in_order(): void
    {
        $this->travelTo('2026-10-12 16:00:00');

        $this->timer('pause', ['session' => $this->session, 'occurred_at' => $this->at('13:00')])->assertOk()->assertJsonPath('timer.accumulated_seconds', 3600);
        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('13:30')])->assertOk();

        $this->assertSame(3600, TimeEntry::query()->sole()->duration_seconds, 'the paused half hour is not counted');
    }

    public function test_a_queued_resume_counts_from_when_it_was_pressed(): void
    {
        $this->travelTo('2026-10-12 13:00:00');
        $this->timer('pause');
        $this->travelTo('2026-10-12 16:00:00');

        $this->timer('resume', ['session' => $this->session, 'occurred_at' => $this->at('14:00')])->assertOk();
        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('15:00')]);

        $this->assertSame(7200, TimeEntry::query()->sole()->duration_seconds, '12–13 and 14–15');
    }

    public function test_replaying_the_same_operation_returns_the_same_result(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $key = $this->key();
        $stop = ['operation_key' => $key, 'session' => $this->session, 'occurred_at' => $this->at('14:00')];

        $first = $this->timer('stop', $stop)->json('timer');
        $this->travelTo('2026-10-12 15:10:00');
        $again = $this->timer('stop', $stop)->assertOk()->json('timer');

        unset($first['server_time'], $again['server_time']);
        $this->assertSame($first, $again);
        $this->assertNotNull($again['entry_public_id']);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_out_of_order_changes_are_refused_not_rewritten(): void
    {
        $this->travelTo('2026-10-12 15:00:00');
        $this->timer('pause', ['session' => $this->session, 'occurred_at' => $this->at('14:00')]);

        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('13:00')])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TIME.TIMER.CHANGED');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_change_before_the_timer_started_is_refused(): void
    {
        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('11:00')])->assertStatus(409);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_future_and_very_old_times_are_refused(): void
    {
        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('12:05')])->assertJsonValidationErrors('occurred_at');

        $this->travelTo('2026-10-16 12:00:00');
        $this->timer('stop', ['session' => $this->session, 'occurred_at' => $this->at('13:00')])->assertJsonValidationErrors('occurred_at');
        $this->assertSame(TimerOperationRequest::MAX_DELAY_HOURS, 72);

        $this->timer('stop', ['session' => $this->session, 'occurred_at' => 'yesterday-ish'])->assertJsonValidationErrors('occurred_at');
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_a_little_clock_drift_is_clamped_to_now(): void
    {
        $this->travelTo('2026-10-12 13:00:00');

        $this->timer('stop', ['session' => $this->session, 'occurred_at' => CarbonImmutable::parse('2026-10-12 13:01:00')->toIso8601String()])->assertOk();

        $this->assertSame('2026-10-12 13:00:00', TimeEntry::query()->sole()->ended_at->toDateTimeString());
    }

    public function test_a_change_for_a_timer_that_was_replaced_is_a_conflict(): void
    {
        /* Another device stopped this timer and started a new one. */
        $this->timer('stop');
        $this->startTimer();

        $this->timer('pause', ['session' => $this->session])->assertStatus(409)->assertJsonPath('error.code', 'TIME.TIMER.CHANGED');
        $this->timer('stop', ['session' => $this->session])->assertOk()->assertJsonPath('timer.public_id', $this->session);

        $this->assertSame(1, TimeEntry::query()->count(), 'the old stop returns its entry; the new timer keeps running');
        $this->assertSame(1, TimeSession::query()->whereNotNull('active_person_id')->count());
    }
}
