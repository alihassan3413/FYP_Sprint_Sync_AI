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
use Illuminate\Support\Facades\DB;

/**
 * The owner's signed adjustment, with a required reason. Two uses:
 *
 * - Correcting an entry ("−0.5h, forgot to stop the timer at lunch"): it is
 *   linked to that entry and copies its person, project, day and billable
 *   flag. The original entry never changes; the effective time is the entry
 *   plus its adjustments, which may not go below zero.
 * - Adding time for a period with no entry to correct ("+48h, Oct 1–9,
 *   before tracking started"): positive only, no invented start/end times.
 */
final class AddTimeAdjustmentAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function forEntry(TimeEntry $entry, int $seconds, string $reason, string $idempotencyKey, User $actor): TimeEntry
    {
        if ($existing = $this->existing($entry->workspace_id, $idempotencyKey)) {
            return $existing;
        }

        if ($entry->source === TimeEntrySource::Adjustment || $entry->isVoided()) {
            throw TimeTrackingException::invalid('Only tracked or manually added time can be corrected.');
        }

        if ($seconds === 0) {
            throw TimeTrackingException::invalid('Enter how many hours to add or remove.');
        }

        try {
            $adjustment = DB::transaction(function () use ($entry, $seconds, $reason, $idempotencyKey, $actor) {
                $effective = $entry->duration_seconds + (int) TimeEntry::query()->active()->where('adjusts_entry_id', $entry->id)->sum('duration_seconds');

                if ($effective + $seconds < 0) {
                    throw TimeTrackingException::invalid('That would make this time negative. At most '.$this->hours($effective).' can be removed.');
                }

                return $this->create($entry->person, $entry->project_id, $entry->task_id, $entry->project_name, $entry->task_title, $entry->period_start->toImmutable(), $entry->period_end->toImmutable(), $seconds, $reason, $entry->billable, $idempotencyKey, $actor, $entry->id);
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($entry->workspace_id, $idempotencyKey) ?? throw $exception;
        }

        $this->audit($adjustment, $actor);

        return $adjustment;
    }

    public function forPeriod(Person $person, Project $project, ?Task $task, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, int $seconds, string $reason, bool $billable, string $idempotencyKey, User $actor): TimeEntry
    {
        if ($existing = $this->existing($person->workspace_id, $idempotencyKey)) {
            return $existing;
        }

        $capacity = TimePeriod::assertHistorical($periodStart, $periodEnd, CarbonImmutable::now($person->workspace->timezone)->startOfDay());

        if ($seconds < 1 || $seconds > $capacity) {
            throw TimeTrackingException::invalid('Enter more than 0 hours, and no more than 24 hours for each day in the period.');
        }

        try {
            $adjustment = $this->create($person, $project->id, $task?->id, $project->name, $task?->title, $periodStart, $periodEnd, $seconds, $reason, $billable, $idempotencyKey, $actor, null);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($person->workspace_id, $idempotencyKey) ?? throw $exception;
        }

        $this->audit($adjustment, $actor);

        return $adjustment;
    }

    private function create(Person $person, ?int $projectId, ?int $taskId, string $projectName, ?string $taskTitle, CarbonImmutable $start, CarbonImmutable $end, int $seconds, string $reason, bool $billable, string $key, User $actor, ?int $adjusts): TimeEntry
    {
        return TimeEntry::query()->forceCreate([
            'workspace_id' => $person->workspace_id,
            'person_id' => $person->id,
            'project_id' => $projectId,
            'task_id' => $taskId,
            'project_name' => $projectName,
            'task_title' => $taskTitle,
            'source' => TimeEntrySource::Adjustment,
            'adjusts_entry_id' => $adjusts,
            'duration_seconds' => $seconds,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'timezone' => $person->workspace->timezone,
            'billable' => $billable,
            'note' => $reason,
            'idempotency_key' => $key,
            'created_by' => $actor->id,
        ]);
    }

    private function existing(int $workspaceId, string $key): ?TimeEntry
    {
        return TimeEntry::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $key)->first();
    }

    private function audit(TimeEntry $adjustment, User $actor): void
    {
        $person = $adjustment->person;
        $sign = $adjustment->duration_seconds < 0 ? '−' : '+';

        $this->auditLogger->handle(
            $person->workspace,
            null,
            $actor,
            AuditAction::TIME_ADJUSTMENT_ADDED,
            "{$actor->name} adjusted {$person->name}'s time on {$adjustment->project_name} by {$sign}{$this->hours(abs($adjustment->duration_seconds))}: {$adjustment->note}",
            $adjustment,
            [
                'person' => $person->name,
                'project' => $adjustment->project_name,
                'duration_seconds' => $adjustment->duration_seconds,
                'period_start' => $adjustment->period_start->toDateString(),
                'period_end' => $adjustment->period_end->toDateString(),
                'reason' => $adjustment->note,
            ],
        );
    }

    private function hours(int $seconds): string
    {
        return rtrim(rtrim(number_format(intdiv($seconds, 36) / 100, 2, '.', ''), '0'), '.').'h';
    }
}
