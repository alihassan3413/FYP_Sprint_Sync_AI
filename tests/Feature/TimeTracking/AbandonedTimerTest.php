<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Data\ActorType;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Actions\StopAbandonedTimerAction;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Data\TimerStopReason;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\Feature\TimeTracking\Concerns\ConfiguresTimePolicy;
use Tests\TestCase;

final class AbandonedTimerTest extends TestCase
{
    use BuildsTimeTeam, ConfiguresTimePolicy, RefreshDatabase;

    private const KARACHI = 'Asia/Karachi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-10-05 08:00');
        $this->setUpTimeTeam(self::KARACHI);
    }

    private function at(string $local, string $timezone = self::KARACHI): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $timezone)->utc());
    }

    /**
     * @param  list<int>  $days
     */
    private function schedule(array $days, string $start, string $end, ?string $timezone = null): void
    {
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $this->usman(), $days, $start, $end, $timezone, $this->owner);
    }

    private function safeguard(?int $afterShiftHours = 2, ?int $maxHours = null): void
    {
        $this->policy([
            'auto_stop_after_shift_seconds' => $afterShiftHours === null ? null : $afterShiftHours * 3600,
            'max_timer_seconds' => $maxHours === null ? null : $maxHours * 3600,
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('time:stop-abandoned-timers')->assertSuccessful();
    }

    private function timerRun(): TimeSession
    {
        return TimeSession::query()->where('person_id', $this->usman()->id)->latest('id')->firstOrFail();
    }

    private function local(?CarbonInterface $moment, string $timezone = self::KARACHI): string
    {
        return CarbonImmutable::instance($moment)->setTimezone($timezone)->format('Y-m-d H:i');
    }

    public function test_the_web_cannot_start_a_timer_while_idle_detection_is_on(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);

        $this->startTimer()->assertForbidden()->assertJsonPath('error.code', 'TIME.DESKTOP_REQUIRED');

        $this->withHeader('X-Inertia', 'true')
            ->actingAs($this->member)
            ->post(route('workspace.time.timer.start', $this->workspace), ['project' => $this->project->public_id, 'operation_key' => $this->key()])
            ->assertSessionHas('error', fn (array $error) => str_contains((string) json_encode($error), 'SprintSync Desktop required'));
        $this->flushHeaders();

        $this->assertSame(0, TimeSession::query()->count());
    }

    public function test_the_desktop_app_still_starts_timers_and_the_web_can_still_control_them(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);

        $this->startDesktopTimer()->assertOk()->assertJsonPath('timer.state', 'running');

        $this->timer('pause')->assertOk()->assertJsonPath('timer.state', 'paused');
        $this->timer('resume')->assertOk()->assertJsonPath('timer.state', 'running');
        $this->timer('stop')->assertOk()->assertJsonPath('timer.state', 'stopped');
        $this->assertNull($this->timerRun()->stop_reason);
    }

    public function test_the_web_starts_timers_when_idle_detection_is_off(): void
    {
        $this->startTimer()->assertOk()->assertJsonPath('timer.state', 'running');
    }

    public function test_the_time_page_tells_web_users_the_desktop_app_is_required(): void
    {
        $this->policy(['idle_enabled' => true, 'idle_seconds' => 300]);

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('policy.idle_enabled', true)->where('timer', null));
    }

    public function test_a_timer_left_running_is_stopped_when_the_shift_cutoff_passes(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 2);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();

        $this->at('2026-10-05 18:59');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'still inside the grace period');

        $this->at('2026-10-05 19:07');
        $this->sweep();

        $session = $this->timerRun();
        $this->assertSame(TimerState::Stopped, $session->state);
        $this->assertSame(TimerStopReason::ShiftEnded, $session->stop_reason);
        $this->assertSame('2026-10-05 19:00', $this->local($session->stopped_at), 'stopped at the cutoff, not when the scheduler noticed');

        $entry = TimeEntry::query()->sole();
        $this->assertSame(10 * 3600, $entry->duration_seconds);
        $this->assertSame('2026-10-05', $entry->period_start->toDateString());

        $review = TimeReview::query()->sole();
        $this->assertSame(ReviewKind::AutoStopped, $review->kind);
        $this->assertStringContainsString('Stopped automatically after the shift ended', $review->summary);

        $log = AuditLog::query()->where('action', AuditAction::TIME_TIMER_AUTO_STOPPED->value)->sole();
        $this->assertSame(ActorType::System, $log->actor_type);
        $this->assertNull($log->user_id);
        $this->assertSame('shift_ended', $log->metadata['stop_reason']);
    }

    public function test_pausing_or_resuming_after_the_shift_counts_as_presence_and_moves_the_cutoff(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 2);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();
        $this->at('2026-10-05 18:00');
        $this->timer('pause')->assertOk();
        $this->at('2026-10-05 18:30');
        $this->timer('resume')->assertOk();

        $this->at('2026-10-05 20:00');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'real overtime keeps running');

        $this->at('2026-10-05 20:45');
        $this->sweep();
        $this->assertSame('2026-10-05 20:30', $this->local($this->timerRun()->stopped_at));
        $this->assertSame((9 + 2) * 3600, TimeEntry::query()->sole()->duration_seconds);
    }

    public function test_an_overnight_shift_is_cut_off_the_next_morning_and_stays_on_its_workday(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');
        $this->safeguard(afterShiftHours: 1);

        $this->at('2026-10-05 18:05');
        $this->startTimer()->assertOk();

        $this->at('2026-10-06 02:30');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'midnight is not the end of an overnight shift');

        $this->at('2026-10-06 04:10');
        $this->sweep();

        $session = $this->timerRun();
        $this->assertSame('2026-10-06 04:00', $this->local($session->stopped_at));
        $this->assertSame('2026-10-05', TimeEntry::query()->sole()->period_start->toDateString(), 'the shift that began on the 5th');
    }

    public function test_a_timer_restarted_after_midnight_inside_an_overnight_shift_uses_that_shift(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '18:00', '03:00');
        $this->safeguard(afterShiftHours: 1);

        $this->at('2026-10-06 00:40');
        $this->startTimer()->assertOk();

        $this->at('2026-10-06 04:05');
        $this->sweep();

        $this->assertSame('2026-10-06 04:00', $this->local($this->timerRun()->stopped_at));
        $this->assertSame('2026-10-05', TimeEntry::query()->sole()->period_start->toDateString());
    }

    public function test_the_longest_run_is_the_fallback_for_people_without_a_schedule(): void
    {
        $this->safeguard(afterShiftHours: 2, maxHours: 12);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();

        $this->at('2026-10-05 20:59');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state);

        $this->at('2026-10-05 21:03');
        $this->sweep();

        $session = $this->timerRun();
        $this->assertSame(TimerStopReason::MaxDuration, $session->stop_reason);
        $this->assertSame('2026-10-05 21:00', $this->local($session->stopped_at));
        $this->assertSame(12 * 3600, TimeEntry::query()->sole()->duration_seconds);
    }

    public function test_evening_work_started_after_the_shift_is_only_covered_by_the_longest_run(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 1, maxHours: 8);

        $this->at('2026-10-05 19:00');
        $this->startTimer()->assertOk();

        $this->at('2026-10-05 23:00');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'the 17:00 shift end does not apply to work started after it');

        $this->at('2026-10-06 03:05');
        $this->sweep();
        $this->assertSame(TimerStopReason::MaxDuration, $this->timerRun()->stop_reason);
        $this->assertSame('2026-10-06 03:00', $this->local($this->timerRun()->stopped_at));
    }

    public function test_the_earlier_of_the_two_rules_wins(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 6, maxHours: 10);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();

        $this->at('2026-10-05 23:30');
        $this->sweep();

        $this->assertSame(TimerStopReason::MaxDuration, $this->timerRun()->stop_reason);
        $this->assertSame('2026-10-05 19:00', $this->local($this->timerRun()->stopped_at));
    }

    public function test_repeated_scheduler_runs_stop_a_timer_once(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 2);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();
        $this->at('2026-10-05 22:00');

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(1, TimeReview::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_TIMER_AUTO_STOPPED->value)->count());

        $stale = $this->timerRun();
        $this->assertNull(app(StopAbandonedTimerAction::class)->handle($stale), 'a stopped timer is never stopped again');

        $this->startTimer()->assertOk();
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'a new timer gets its own cutoff');
    }

    public function test_a_timer_the_person_stopped_meanwhile_is_left_alone(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 2);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();
        $loaded = $this->timerRun();

        $this->at('2026-10-05 22:00');
        $this->timer('stop')->assertOk();

        $this->assertNull(app(StopAbandonedTimerAction::class)->handle($loaded));
        $this->assertNull($this->timerRun()->stop_reason);
        $this->assertSame(0, TimeReview::query()->count());
        $this->assertSame('2026-10-05 22:00', $this->local($this->timerRun()->stopped_at));
    }

    public function test_a_paused_timer_is_closed_without_adding_time(): void
    {
        $this->safeguard(afterShiftHours: null, maxHours: 8);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();
        $this->at('2026-10-05 12:00');
        $this->timer('pause')->assertOk();

        $this->at('2026-10-05 20:30');
        $this->sweep();

        $this->assertSame('2026-10-05 20:00', $this->local($this->timerRun()->stopped_at));
        $this->assertSame(3 * 3600, TimeEntry::query()->sole()->duration_seconds);
    }

    public function test_turning_the_safeguard_on_never_stops_a_timer_in_the_past(): void
    {
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();

        $this->at('2026-10-07 10:00');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state, 'off by default');

        $this->safeguard(afterShiftHours: 2);
        $this->sweep();

        $this->assertSame('2026-10-07 10:00', $this->local($this->timerRun()->stopped_at), 'stopped when the rule began, not on Monday evening');
    }

    public function test_the_cutoff_follows_the_schedule_timezone_not_the_project_timezone(): void
    {
        $newYork = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Night desk', 'timezone' => 'America/New_York']);
        $newYork->members()->attach($this->member->id, ['role' => 'member']);
        $this->schedule([1, 2, 3, 4, 5], '09:00', '17:00');
        $this->safeguard(afterShiftHours: 1);

        $this->at('2026-10-05 09:00');
        $this->startTimer(['project' => $newYork->public_id])->assertOk();
        $this->assertSame('America/New_York', $this->timerRun()->timezone);

        $this->at('2026-10-05 18:05');
        $this->sweep();

        $session = $this->timerRun();
        $this->assertSame('2026-10-05 18:00', $this->local($session->stopped_at), '17:00 Karachi plus one hour');
        $this->assertStringContainsString('EDT', TimeReview::query()->sole()->summary, 'the review shows the time in the project timezone');
    }

    public function test_a_shift_across_the_daylight_saving_change_ends_at_its_wall_clock_time(): void
    {
        $this->schedule([6], '22:00', '06:00', 'America/New_York');
        $this->safeguard(afterShiftHours: 1);

        $this->at('2026-10-31 22:00', 'America/New_York');
        $this->startTimer()->assertOk();

        $this->at('2026-11-01 06:30', 'America/New_York');
        $this->sweep();
        $this->assertSame(TimerState::Running, $this->timerRun()->state);

        $this->at('2026-11-01 07:10', 'America/New_York');
        $this->sweep();

        $session = $this->timerRun();
        $this->assertSame('2026-11-01 07:00', $this->local($session->stopped_at, 'America/New_York'));
        $this->assertSame(10 * 3600, TimeEntry::query()->sole()->duration_seconds, '22:00 EDT to 07:00 EST is ten hours');
    }

    public function test_the_owner_saves_the_safeguard_in_time_settings(): void
    {
        $this->actingAs($this->owner)->put(route('workspace.time.settings.policy', $this->workspace), [
            'idle_enabled' => false,
            'lunch_enabled' => false,
            'overtime_mode' => 'track_only',
            'integrity_enabled' => false,
            'shift_stop_enabled' => true,
            'shift_stop_hours' => 3,
            'max_timer_enabled' => true,
            'max_timer_hours' => 16,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('workspace.time.settings.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('policy.shift_stop_enabled', true)
                ->where('policy.shift_stop_hours', 3)
                ->where('policy.max_timer_enabled', true)
                ->where('policy.max_timer_hours', 16)
                ->where('policy.auto_stop_after_shift_seconds', 3 * 3600)
                ->where('policy.max_timer_seconds', 16 * 3600));

        $this->actingAs($this->owner)->put(route('workspace.time.settings.policy', $this->workspace), [
            'idle_enabled' => false,
            'lunch_enabled' => false,
            'overtime_mode' => 'track_only',
            'integrity_enabled' => false,
            'shift_stop_enabled' => true,
            'shift_stop_hours' => 30,
        ])->assertSessionHasErrors('shift_stop_hours');
    }

    public function test_the_person_sees_why_their_timer_stopped(): void
    {
        $this->safeguard(afterShiftHours: null, maxHours: 8);

        $this->at('2026-10-05 09:00');
        $this->startTimer()->assertOk();
        $this->at('2026-10-05 18:00');
        $this->sweep();

        $this->actingAs($this->member)->get(route('workspace.time.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('entries.0.stop_reason_label', 'Stopped automatically after running too long'));

        $this->actingAs($this->owner)->get(route('workspace.time.index', [$this->workspace, 'person' => $this->usman()->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviews.0.kind', 'auto_stopped')
                ->where('reviews.0.evidence.reason', 'max_duration')
                ->has('reviews.0.evidence.stopped_at')
                ->where('reviews.0.entry', $page->toArray()['props']['entries'][0]['public_id']));
    }
}
