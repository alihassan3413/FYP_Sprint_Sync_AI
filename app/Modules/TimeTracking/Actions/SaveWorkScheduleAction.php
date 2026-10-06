<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Models\WorkSchedule;
use App\Modules\Workspace\Models\Workspace;

/**
 * Saves an expected schedule (the workspace's, or one person's) as a new
 * version from now on. Empty days clear it. It never creates or stops time.
 */
final class SaveWorkScheduleAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  list<int>  $days  ISO weekdays, 1 = Monday
     */
    public function handle(Workspace $workspace, ?Person $person, array $days, ?string $start, ?string $end, ?string $timezone, User $actor): WorkSchedule
    {
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days);
        $empty = $days === [] || $start === null || $end === null;

        $schedule = WorkSchedule::query()->forceCreate([
            'workspace_id' => $workspace->id,
            'person_id' => $person?->id,
            'days' => $empty ? null : $days,
            'start_time' => $empty ? null : $start,
            'end_time' => $empty ? null : $end,
            'timezone' => $timezone ?: $workspace->timezone,
            'effective_from' => now(),
            'created_by' => $actor->id,
        ]);

        $who = $person?->name ?? 'the workspace';
        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::TIME_SCHEDULE_CHANGED,
            $empty ? "{$actor->name} cleared the expected schedule for {$who}." : "{$actor->name} set the expected schedule for {$who}: {$start}–{$end}.",
            $schedule,
            ['person' => $person?->name, 'days' => $schedule->days, 'start' => $schedule->start_time, 'end' => $schedule->end_time, 'timezone' => $schedule->timezone],
        );

        return $schedule;
    }
}
