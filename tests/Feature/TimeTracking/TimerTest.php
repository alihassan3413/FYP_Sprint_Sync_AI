<?php

declare(strict_types=1);

namespace Tests\Feature\TimeTracking;

use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Actions\StartTimerAction;
use App\Modules\TimeTracking\Actions\StopTimerAction;
use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TimeTracking\Concerns\BuildsTimeTeam;
use Tests\TestCase;

/**
 * Start / pause / resume / stop, from the server's clock, safe to retry.
 */
final class TimerTest extends TestCase
{
    use BuildsTimeTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->setUpTimeTeam();
    }

    private function at(string $moment, string $timezone = 'UTC'): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, $timezone)->utc());
    }

    private function entry(): TimeEntry
    {
        return TimeEntry::query()->sole();
    }

    /* ---- The basics ----------------------------------------------------- */

    public function test_start_and_read_the_current_timer(): void
    {
        $this->startTimer(['task' => $this->task->public_id, 'note' => 'Customer follow-ups'])
            ->assertOk()
            ->assertJsonPath('timer.state', 'running')
            ->assertJsonPath('timer.project.public_id', $this->project->public_id)
            ->assertJsonPath('timer.task.title', 'Flood Quote Workflow');

        $this->at('2026-10-12 09:30:00');

        $response = $this->actingAs($this->member)->getJson(route('workspace.time.timer.current', $this->workspace))
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('timer.state', 'running')
            ->assertJsonPath('timer.elapsed_seconds', 1800)
            ->assertJsonPath('timer.accumulated_seconds', 0)
            ->assertJsonPath('timer.running_since', '2026-10-12T09:00:00+00:00')
            ->assertJsonPath('server_time', '2026-10-12T09:30:00+00:00');

        $this->assertArrayNotHasKey('id', $response->json('timer'));
        $this->assertStringNotContainsString('"project_id"', $response->getContent());
    }

    public function test_no_timer_reads_as_inactive(): void
    {
        $this->actingAs($this->member)->getJson(route('workspace.time.timer.current', $this->workspace))
            ->assertOk()
            ->assertExactJson(['active' => false, 'timer' => null, 'server_time' => '2026-10-12T09:00:00+00:00']);
    }

    public function test_paused_time_does_not_count(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 13:00:00');
        $this->timer('pause')->assertJsonPath('timer.state', 'paused')->assertJsonPath('timer.accumulated_seconds', 14400);
        $this->at('2026-10-12 14:00:00');
        $this->timer('resume')->assertJsonPath('timer.state', 'running');
        $this->at('2026-10-12 17:00:00');
        $this->timer('stop')->assertOk()->assertJsonPath('timer.state', 'stopped')->assertJsonPath('timer.elapsed_seconds', 25200);

        $entry = $this->entry();
        $this->assertSame(TimeEntrySource::Tracked, $entry->source);
        $this->assertSame(25200, $entry->duration_seconds, '09–13 and 14–17: 7 hours');
        $this->assertSame(14400, $entry->longest_segment_seconds, 'kept for a future "over 12h without a break" check');
        $this->assertSame('2026-10-12 09:00:00', $entry->started_at->toDateTimeString());
        $this->assertSame('2026-10-12 17:00:00', $entry->ended_at->toDateTimeString());
        $this->assertSame('2026-10-12', $entry->period_start->toDateString());
        $this->assertNull(TimeSession::query()->sole()->active_person_id, 'free to start another timer');
    }

    public function test_a_stop_while_paused_counts_only_running_time(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 10:00:00');
        $this->timer('pause');
        $this->at('2026-10-12 15:00:00');
        $this->timer('stop');

        $this->assertSame(3600, $this->entry()->duration_seconds);
    }

    public function test_long_sessions_are_allowed(): void
    {
        $this->startTimer();
        $this->at('2026-10-13 00:30:00');
        $this->timer('stop')->assertOk();

        $this->assertSame(55800, $this->entry()->duration_seconds, '15.5 hours is recorded, not cut off');
        $this->assertSame(55800, $this->entry()->longest_segment_seconds);
    }

    /* ---- Timezones ------------------------------------------------------ */

    public function test_an_overnight_session_in_lahore_is_one_session_on_the_day_it_started(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);
        $this->at('2026-10-12 18:05:00', 'Asia/Karachi');
        $this->startTimer();
        $this->at('2026-10-13 02:03:00', 'Asia/Karachi');
        $this->timer('stop');

        $entry = $this->entry();
        $this->assertSame(28680, $entry->duration_seconds, '7h 58m');
        $this->assertSame('2026-10-12 13:05:00', $entry->started_at->toDateTimeString(), 'stored in UTC');
        $this->assertSame('2026-10-12', $entry->period_start->toDateString(), 'filed under the business day it started');
        $this->assertSame('Asia/Karachi', $entry->timezone);
    }

    public function test_daylight_saving_changes_never_change_real_durations(): void
    {
        $this->workspace->update(['timezone' => 'America/New_York']);
        $this->at('2026-11-01 00:30:00', 'America/New_York');
        $this->startTimer();
        $this->at('2026-11-01 03:30:00', 'America/New_York');
        $this->timer('stop');

        $this->assertSame(14400, $this->entry()->duration_seconds, 'clocks went back an hour: 4 real hours, not 3');
        $this->assertSame('2026-11-01', $this->entry()->period_start->toDateString());
    }

    public function test_months_follow_the_workspace_calendar_not_utc(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);
        $this->at('2026-10-31 19:30:00');
        $this->startTimer();
        $this->at('2026-10-31 21:30:00');
        $this->timer('stop');

        $this->assertSame('2026-11-01', $this->entry()->period_start->toDateString(), '00:30 on Nov 1 in Lahore');

        $totals = app(TimeTotals::class);
        $this->assertSame(0, $totals->seconds(new TimeQuery($this->workspace->id, from: CarbonImmutable::parse('2026-10-01'), to: CarbonImmutable::parse('2026-10-31'))));
        $this->assertSame(7200, $totals->seconds(new TimeQuery($this->workspace->id, from: CarbonImmutable::parse('2026-11-01'), to: CarbonImmutable::parse('2026-11-30'))));
    }

    /* ---- One active timer ------------------------------------------------ */

    public function test_a_second_timer_is_refused(): void
    {
        $this->startTimer()->assertOk();

        $this->startTimer()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TIME.TIMER.ALREADY_RUNNING');

        $this->timer('pause');
        $this->startTimer()->assertStatus(409, 'a paused timer is still active');

        $this->assertSame(1, TimeSession::query()->count());
    }

    public function test_the_database_refuses_two_active_timers(): void
    {
        $this->startTimer();
        $session = TimeSession::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('time_sessions')->insert([
            ...collect($session->getAttributes())->except(['id', 'public_id'])->all(),
            'public_id' => strtolower((string) str()->ulid()),
        ]);
    }

    public function test_after_stopping_a_new_timer_can_start(): void
    {
        $this->startTimer();
        $this->timer('stop');

        $this->startTimer()->assertOk();
        $this->assertSame(2, TimeSession::query()->count());
    }

    /* ---- Retries and races ---------------------------------------------- */

    public function test_retrying_start_with_the_same_key_returns_the_same_timer(): void
    {
        $key = $this->key();

        $first = $this->startTimer(['operation_key' => $key])->json('timer.public_id');
        $second = $this->startTimer(['operation_key' => $key])->assertOk()->json('timer.public_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, TimeSession::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_TIMER_STARTED->value)->count());
    }

    public function test_pausing_twice_counts_the_stretch_once(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 10:00:00');
        $key = $this->key();
        $this->timer('pause', ['operation_key' => $key]);
        $this->at('2026-10-12 10:20:00');
        $this->timer('pause', ['operation_key' => $key])->assertJsonPath('timer.accumulated_seconds', 3600);
        $this->timer('pause')->assertOk()->assertJsonPath('timer.accumulated_seconds', 3600);
    }

    public function test_resuming_twice_does_not_restart_the_stretch(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 10:00:00');
        $this->timer('pause');
        $this->at('2026-10-12 11:00:00');
        $this->timer('resume');
        $this->at('2026-10-12 11:30:00');
        $this->timer('resume')->assertOk()->assertJsonPath('timer.running_since', '2026-10-12T11:00:00+00:00');
        $this->at('2026-10-12 12:00:00');
        $this->timer('stop');

        $this->assertSame(7200, $this->entry()->duration_seconds);
    }

    public function test_a_retried_stop_returns_the_same_entry(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 12:00:00');
        $key = $this->key();

        $first = $this->timer('stop', ['operation_key' => $key])->json('timer');
        $this->at('2026-10-12 12:05:00');
        $retry = $this->timer('stop', ['operation_key' => $key])->assertOk()->json('timer');

        $this->assertSame($first['entry_public_id'], $retry['entry_public_id']);
        $this->assertSame(10800, $retry['elapsed_seconds']);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TIME_TIMER_STOPPED->value)->count());
    }

    public function test_a_second_stop_adds_nothing(): void
    {
        $this->startTimer();
        $session = TimeSession::query()->sole()->public_id;
        $this->at('2026-10-12 12:00:00');
        $this->timer('stop');

        $this->timer('stop')->assertStatus(409)->assertJsonPath('error.code', 'TIME.TIMER.NOT_RUNNING');
        $this->timer('stop', ['session' => $session])->assertOk()->assertJsonPath('timer.entry_public_id', $this->entry()->public_id);

        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(10800, (int) TimeEntry::query()->sum('duration_seconds'));
    }

    public function test_concurrent_stops_record_one_entry(): void
    {
        $this->startTimer();
        $this->at('2026-10-12 12:00:00');
        $action = app(StopTimerAction::class);

        $action->handle($this->usman()->fresh(), $this->key(), $this->member);

        try {
            $action->handle($this->usman()->fresh(), $this->key(), $this->member);
            $this->fail('the second stop must not record time');
        } catch (TimeTrackingException) {
        }

        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_concurrent_starts_leave_one_active_timer(): void
    {
        $action = app(StartTimerAction::class);
        $action->handle($this->usman()->fresh(), $this->project, null, true, null, $this->key(), $this->member);

        $this->expectException(TimeTrackingException::class);

        try {
            $action->handle($this->usman()->fresh(), $this->project, null, true, null, $this->key(), $this->member);
        } finally {
            $this->assertSame(1, TimeSession::query()->whereNotNull('active_person_id')->count());
        }
    }

    public function test_a_key_cannot_be_reused_for_a_different_action(): void
    {
        $key = $this->key();
        $this->startTimer(['operation_key' => $key]);

        $this->timer('stop', ['operation_key' => $key])->assertStatus(409)->assertJsonPath('error.code', 'TIME.OPERATION.CONFLICT');
        $this->assertSame(TimerState::Running, TimeSession::query()->sole()->state);
    }

    /* ---- Projects and tasks --------------------------------------------- */

    public function test_the_task_must_belong_to_the_project(): void
    {
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create();
        $otherTask = Task::factory()->forProject($otherProject)->create();

        $this->startTimer(['task' => $otherTask->public_id])->assertUnprocessable()->assertJsonValidationErrors('task');
        $this->assertSame(0, TimeSession::query()->count());
    }

    public function test_another_workspaces_project_or_a_numeric_id_is_refused(): void
    {
        $foreign = Project::factory()->forWorkspace(Workspace::factory()->create())->create();

        $this->startTimer(['project' => $foreign->public_id])->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->startTimer(['project' => (string) $this->project->id])->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->assertSame(0, TimeSession::query()->count());
    }

    public function test_members_only_track_projects_they_can_open(): void
    {
        $private = Project::factory()->forWorkspace($this->workspace)->create();

        $this->startTimer(['project' => $private->public_id])->assertUnprocessable();
        $this->startTimer(['project' => $private->public_id], $this->owner)->assertOk();
    }

    public function test_time_survives_its_project_being_deleted(): void
    {
        $this->startTimer(['task' => $this->task->public_id]);
        $this->at('2026-10-12 11:00:00');
        $this->timer('stop');

        $this->project->delete();

        $entry = $this->entry();
        $this->assertNull($entry->project_id);
        $this->assertNull($entry->task_id);
        $this->assertSame('RocketFlood CSR', $entry->project_name);
        $this->assertSame('Flood Quote Workflow', $entry->task_title);
        $this->assertSame(7200, app(TimeTotals::class)->seconds(new TimeQuery($this->workspace->id, personId: $this->usman()->id)));
    }

    /* ---- Who --------------------------------------------------------------- */

    public function test_people_only_ever_act_on_their_own_timer(): void
    {
        $this->startTimer([], $this->owner)->assertOk();
        $this->startTimer()->assertOk();

        $this->timer('stop')->assertOk();

        $this->assertSame([$this->usman()->id], TimeEntry::query()->pluck('person_id')->all());
        $this->assertSame(TimerState::Running, TimeSession::query()->where('person_id', $this->team['Ali Hassan']->id)->sole()->state);
    }

    public function test_an_account_without_a_team_profile_cannot_track(): void
    {
        $this->usman()->forceFill(['user_id' => null])->save();

        $this->startTimer()->assertForbidden()->assertJsonPath('error.code', 'TIME.NO_PROFILE');
    }

    public function test_pause_and_resume_are_not_audited(): void
    {
        $this->startTimer();
        $this->timer('pause');
        $this->timer('resume');
        $this->timer('stop');

        $this->assertSame(
            [AuditAction::TIME_TIMER_STARTED->value, AuditAction::TIME_TIMER_STOPPED->value],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_qa_command_records_an_overnight_session(): void
    {
        $this->workspace->update(['timezone' => 'Asia/Karachi']);

        $this->artisan('time:simulate-session', ['email' => $this->member->email, 'project' => 'RocketFlood CSR', 'from' => '2026-10-12 18:05', 'to' => '2026-10-13 02:03'])
            ->expectsOutputToContain('Recorded 7h 58m for Muhammad Usman Ghani on RocketFlood CSR, filed under 2026-10-12 (Asia/Karachi).')
            ->assertSuccessful();

        $this->assertSame(28680, $this->entry()->duration_seconds);
        $this->assertSame('2026-10-12 13:05:00', $this->entry()->started_at->toDateTimeString());
    }
}
