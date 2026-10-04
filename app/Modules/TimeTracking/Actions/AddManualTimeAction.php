<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Support\TimePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Adds time worked on one day without a timer ("2.5h on Oct 12"). No start
 * or end time is invented. Idempotent: the request key is UNIQUE per
 * workspace, so a double submit returns the first entry.
 */
final class AddManualTimeAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Person $person, Project $project, ?Task $task, CarbonImmutable $workDate, int $seconds, bool $billable, ?string $note, string $idempotencyKey, User $actor): TimeEntry
    {
        if ($existing = $this->existing($person, $idempotencyKey)) {
            return $existing;
        }

        if ($project->workspace_id !== $person->workspace_id || ($task !== null && $task->project_id !== $project->id)) {
            throw new LogicException('Time, its project and its task must belong together.');
        }

        if ($seconds < 1 || $seconds > TimePeriod::MAX_DAY_SECONDS) {
            throw TimeTrackingException::invalid('Enter between 0.01 and 24 hours for one day.');
        }

        TimePeriod::assertHistorical($workDate, $workDate, CarbonImmutable::now($person->workspace->timezone)->startOfDay());

        try {
            $entry = TimeEntry::query()->forceCreate([
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'project_id' => $project->id,
                'task_id' => $task?->id,
                'project_name' => $project->name,
                'task_title' => $task?->title,
                'source' => TimeEntrySource::Manual,
                'duration_seconds' => $seconds,
                'period_start' => $workDate->toDateString(),
                'period_end' => $workDate->toDateString(),
                'timezone' => $person->workspace->timezone,
                'billable' => $billable,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($person, $idempotencyKey) ?? throw $exception;
        }

        $this->auditLogger->handle(
            $person->workspace,
            null,
            $actor,
            AuditAction::TIME_MANUAL_ADDED,
            "{$actor->name} added {$this->hours($seconds)} for {$person->name} on {$project->name} ({$workDate->format('M j')}).",
            $entry,
            ['person' => $person->name, 'project' => $project->name, 'duration_seconds' => $seconds, 'date' => $workDate->toDateString()],
        );

        return $entry;
    }

    private function existing(Person $person, string $key): ?TimeEntry
    {
        $entry = TimeEntry::query()->where('workspace_id', $person->workspace_id)->where('idempotency_key', $key)->first();

        if ($entry !== null && $entry->person_id !== $person->id) {
            throw TimeTrackingException::invalid('That request id was already used.');
        }

        return $entry;
    }

    private function hours(int $seconds): string
    {
        return rtrim(rtrim(number_format(intdiv($seconds, 36) / 100, 2, '.', ''), '0'), '.').'h';
    }
}
