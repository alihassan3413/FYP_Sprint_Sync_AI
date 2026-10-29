<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActiveTimer;
use App\Modules\TimeTracking\Support\LunchBreaks;
use App\Modules\TimeTracking\Support\Schedules;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use App\Modules\TimeTracking\Support\TimerStretches;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stops the person's timer and records its time as one tracked entry.
 *
 * The stop is a conditional update from running/paused that also clears
 * active_person_id (so a new timer may start); only one request can win it.
 * The entry is created in the same transaction and is UNIQUE per timer. A
 * retry returns the same stopped timer and entry: by operation key, or, when
 * the client names the timer it means ($sessionPublicId), by that timer once
 * it is stopped. Time is never added twice.
 *
 * A stop synced late from the desktop app ends the time when the person
 * pressed Stop (occurred_at, see ActiveTimer), not when it arrived.
 *
 * Stopping during lunch ends the lunch first (a paid-lunch entry); stopping
 * during an idle pause closes the idle period. The tracked entry records how
 * much of the work fell outside the person's expected schedule, judged by the
 * schedule in force when the timer started.
 *
 * The entry belongs to the session's workday (Schedules::workday): the day
 * the timer started on in the workspace's timezone, or the day an overnight
 * scheduled shift began (a timer restarted at 00:40 in an 18:00–03:00 shift
 * stays on the evening's day). 18:05 → 02:03 is one session on the 12th.
 */
final class StopTimerAction
{
    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly LunchBreaks $lunches,
        private readonly Schedules $schedules,
    ) {}

    public function handle(Person $person, string $operationKey, User $actor, ?string $sessionPublicId = null, ?CarbonImmutable $occurredAt = null): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'stop')) {
            return $replayed->load('entry');
        }

        if ($sessionPublicId !== null) {
            $named = TimeSession::query()->where('person_id', $person->id)->where('public_id', $sessionPublicId)->first();

            if ($named?->state === TimerState::Stopped) {
                return $named->load('entry');
            }

            if ($named === null) {
                throw TimeTrackingException::timerChanged();
            }
        }

        try {
            [$session, $entry] = DB::transaction(function () use ($person, $operationKey, $sessionPublicId, $occurredAt) {
                $session = ActiveTimer::for($person, $sessionPublicId);
                $now = ActiveTimer::moment($session, $occurredAt);
                $stretch = $session->runningSeconds($now);
                $seconds = $session->accumulated_seconds + $stretch;
                $longest = max($session->longest_segment_seconds, $stretch);

                $stopped = TimeSession::query()
                    ->whereKey($session->getKey())
                    ->whereIn('state', [TimerState::Running->value, TimerState::Paused->value, TimerState::Lunch->value])
                    ->update([
                        'state' => TimerState::Stopped->value,
                        'active_person_id' => null,
                        'accumulated_seconds' => $seconds,
                        'longest_segment_seconds' => $longest,
                        'segment_started_at' => null,
                        'lunch_started_at' => null,
                        'pause_reason' => null,
                        'stopped_at' => $now,
                        'state_changed_at' => $now,
                        'updated_at' => now(),
                    ]);

                if ($stopped !== 1) {
                    throw TimeTrackingException::noActiveTimer();
                }

                TimerStretches::record($session, $session->state === TimerState::Running ? $session->segment_started_at : null, $now);
                $this->lunches->close($session, $now);

                if ($session->pause_reason === 'idle') {
                    TimeIdlePeriod::query()->where('time_session_id', $session->id)->whereNull('ended_at')->update(['ended_at' => $now, 'updated_at' => now()]);
                }

                $schedule = $this->schedules->for($session->person, $session->started_at);

                $workDate = $this->schedules->workdayOf($session);
                $entry = TimeEntry::query()->forceCreate([
                    'workspace_id' => $session->workspace_id,
                    'person_id' => $session->person_id,
                    'project_id' => $session->project_id,
                    'task_id' => $session->task_id,
                    'project_name' => $session->project_name,
                    'task_title' => $session->task_title,
                    'source' => TimeEntrySource::Tracked,
                    'time_session_id' => $session->id,
                    'started_at' => $session->started_at,
                    'ended_at' => $now,
                    'duration_seconds' => $seconds,
                    'longest_segment_seconds' => $longest,
                    'outside_schedule_seconds' => $schedule === null ? null : $this->schedules->outsideSeconds($schedule, TimerStretches::for($session)),
                    'period_start' => $workDate,
                    'period_end' => $workDate,
                    'timezone' => $session->timezone,
                    'billable' => $session->billable,
                    'note' => $session->note,
                    'created_by' => $session->started_by,
                ]);

                $this->operations->record($person, $operationKey, 'stop', $session);

                return [$session->refresh(), $entry];
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'stop')?->load('entry') ?? throw $exception;
        }

        $this->auditLogger->handle(
            $person->workspace,
            null,
            $actor,
            AuditAction::TIME_TIMER_STOPPED,
            "{$actor->name} stopped {$person->name}'s timer on {$session->project_name} ({$this->hours($entry->duration_seconds)}).",
            $entry,
            [
                'person' => $person->name,
                'project' => $session->project_name,
                'duration_seconds' => $entry->duration_seconds,
                /* Synced from the desktop app after being offline: stopped earlier than it arrived. */
                'synced_late' => $occurredAt !== null && $entry->ended_at->diffInSeconds(now()) > 60,
            ],
        );

        return $session->setRelation('entry', $entry);
    }

    private function hours(int $seconds): string
    {
        return intdiv($seconds, 3600).'h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
