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
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimerOperationLog;
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
 * The entry belongs to the business day the timer started on, in the
 * workspace's timezone, so 18:05 → 02:03 is one session on the 12th.
 */
final class StopTimerAction
{
    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Person $person, string $operationKey, User $actor, ?string $sessionPublicId = null): TimeSession
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
                throw TimeTrackingException::noActiveTimer();
            }
        }

        try {
            [$session, $entry] = DB::transaction(function () use ($person, $operationKey) {
                $session = TimeSession::query()->where('active_person_id', $person->id)->first() ?? throw TimeTrackingException::noActiveTimer();
                $now = now();
                $stretch = $session->runningSeconds($now);
                $seconds = $session->accumulated_seconds + $stretch;
                $longest = max($session->longest_segment_seconds, $stretch);

                $stopped = TimeSession::query()
                    ->whereKey($session->getKey())
                    ->whereIn('state', [TimerState::Running->value, TimerState::Paused->value])
                    ->update([
                        'state' => TimerState::Stopped->value,
                        'active_person_id' => null,
                        'accumulated_seconds' => $seconds,
                        'longest_segment_seconds' => $longest,
                        'segment_started_at' => null,
                        'stopped_at' => $now,
                        'updated_at' => $now,
                    ]);

                if ($stopped !== 1) {
                    throw TimeTrackingException::noActiveTimer();
                }

                $workDate = $session->started_at->copy()->setTimezone($session->timezone)->toDateString();
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
            ['person' => $person->name, 'project' => $session->project_name, 'duration_seconds' => $entry->duration_seconds],
        );

        return $session->setRelation('entry', $entry);
    }

    private function hours(int $seconds): string
    {
        return intdiv($seconds, 3600).'h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
