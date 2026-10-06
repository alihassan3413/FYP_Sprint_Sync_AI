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
use App\Modules\TimeTracking\Models\TimeEntryBatch;
use App\Modules\TimeTracking\Support\TimePeriod;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * "Add previous hours": one total per person for one project and period,
 * with one shared reason ("Before SprintSync tracking started"). Each row is
 * an adjustment entry (a total for the period, never invented shifts).
 *
 * All rows are checked before anything is written and are saved in one
 * transaction: one bad row saves nothing. The batch's request key is UNIQUE,
 * so a double submit returns the first batch instead of adding the hours again.
 */
final class AddHistoricalHoursAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  list<array{person: Person, seconds: int}>  $rows
     */
    public function handle(Workspace $workspace, Project $project, ?Task $task, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $reason, bool $billable, array $rows, string $idempotencyKey, User $actor): TimeEntryBatch
    {
        if ($existing = $this->existing($workspace, $idempotencyKey)) {
            return $existing;
        }

        if ($project->workspace_id !== $workspace->id || ($task !== null && $task->project_id !== $project->id)) {
            throw new LogicException('Time, its project and its task must belong together.');
        }

        $capacity = TimePeriod::assertHistorical($periodStart, $periodEnd, CarbonImmutable::now($workspace->timezone)->startOfDay());

        if ($rows === []) {
            throw TimeTrackingException::invalid('Enter hours for at least one person.');
        }

        $seen = [];
        foreach ($rows as ['person' => $person, 'seconds' => $seconds]) {
            if ($person->workspace_id !== $workspace->id || isset($seen[$person->id])) {
                throw TimeTrackingException::invalid('Each person can appear once.');
            }

            if ($seconds < 1 || $seconds > $capacity) {
                throw TimeTrackingException::invalid("{$person->name}: enter more than 0 hours, and no more than 24 hours for each day in the period.");
            }

            $seen[$person->id] = true;
        }

        try {
            $batch = DB::transaction(function () use ($workspace, $project, $task, $periodStart, $periodEnd, $reason, $billable, $rows, $idempotencyKey, $actor) {
                $batch = TimeEntryBatch::query()->forceCreate([
                    'workspace_id' => $workspace->id,
                    'idempotency_key' => $idempotencyKey,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'note' => $reason,
                    'created_by' => $actor->id,
                ]);

                foreach ($rows as ['person' => $person, 'seconds' => $seconds]) {
                    TimeEntry::query()->forceCreate([
                        'workspace_id' => $workspace->id,
                        'person_id' => $person->id,
                        'project_id' => $project->id,
                        'task_id' => $task?->id,
                        'project_name' => $project->name,
                        'task_title' => $task?->title,
                        'source' => TimeEntrySource::Adjustment,
                        'batch_id' => $batch->id,
                        'duration_seconds' => $seconds,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                        'timezone' => $workspace->timezone,
                        'billable' => $billable,
                        'note' => $reason,
                        'created_by' => $actor->id,
                    ]);
                }

                return $batch;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($workspace, $idempotencyKey) ?? throw $exception;
        }

        $total = array_sum(array_column($rows, 'seconds'));
        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::TIME_HISTORICAL_ADDED,
            "{$actor->name} added previous hours for ".count($rows)." people on {$project->name} ({$periodStart->format('M j')}–{$periodEnd->format('M j')}): {$reason}",
            $batch,
            ['project' => $project->name, 'people' => count($rows), 'total_seconds' => $total, 'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString()],
        );

        return $batch;
    }

    private function existing(Workspace $workspace, string $key): ?TimeEntryBatch
    {
        return TimeEntryBatch::query()->where('workspace_id', $workspace->id)->where('idempotency_key', $key)->first();
    }
}
