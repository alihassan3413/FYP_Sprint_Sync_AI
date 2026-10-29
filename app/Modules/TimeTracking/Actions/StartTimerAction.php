<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Starts a timer for a person on a project (and optionally one of its tasks).
 *
 * At most one active timer per person: the insert sets active_person_id,
 * which is UNIQUE, so a second start (concurrent or not) cannot be stored and
 * is refused. A retry with the same operation key returns the first timer.
 * Times come from the server clock.
 */
final class StartTimerAction
{
    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Person $person, Project $project, ?Task $task, bool $billable, ?string $note, string $operationKey, User $actor): TimeSession
    {
        if ($project->workspace_id !== $person->workspace_id || ($task !== null && $task->project_id !== $project->id)) {
            throw new LogicException('A timer, its project and its task must belong together.');
        }

        if ($replayed = $this->operations->replay($person, $operationKey, 'start')) {
            return $replayed;
        }

        try {
            $session = DB::transaction(function () use ($person, $project, $task, $billable, $note, $operationKey, $actor) {
                $now = now();
                $session = TimeSession::query()->forceCreate([
                    'workspace_id' => $person->workspace_id,
                    'person_id' => $person->id,
                    'project_id' => $project->id,
                    'task_id' => $task?->id,
                    'project_name' => $project->name,
                    'task_title' => $task?->title,
                    'state' => TimerState::Running,
                    'active_person_id' => $person->id,
                    'started_at' => $now,
                    'state_changed_at' => $now,
                    'segment_started_at' => $now,
                    'accumulated_seconds' => 0,
                    'longest_segment_seconds' => 0,
                    'timezone' => $project->effectiveTimezone(),
                    'billable' => $billable,
                    'note' => $note,
                    'started_by' => $actor->id,
                ]);

                $this->operations->record($person, $operationKey, 'start', $session);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->operations->replay($person, $operationKey, 'start') ?? throw TimeTrackingException::timerAlreadyRunning();
        }

        $this->auditLogger->handle(
            $person->workspace,
            null,
            $actor,
            AuditAction::TIME_TIMER_STARTED,
            "{$actor->name} started a timer for {$person->name} on {$project->name}.",
            $session,
            ['person' => $person->name, 'project' => $project->name, 'task' => $task?->title],
        );

        return $session;
    }
}
