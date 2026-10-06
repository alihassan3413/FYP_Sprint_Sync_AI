<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\LeaveType;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeHoliday;
use App\Modules\TimeTracking\Models\TimeLeave;
use App\Modules\TimeTracking\Support\Durations;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Holidays and leave (owner only). Both are credits toward paid / expected
 * hours, never tracked or client-billable time, and never invent timer
 * entries. Removing one keeps it on record (removed_at) and stops it counting.
 * A repeated add (same request key, or the same holiday on the same day) adds
 * nothing.
 */
final class ManageTimeOffAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  list<Person>  $people  empty = everyone
     */
    public function addHoliday(Workspace $workspace, CarbonImmutable $date, string $name, int $creditSeconds, array $people, User $actor): TimeHoliday
    {
        $existing = TimeHoliday::query()->active()->where('workspace_id', $workspace->id)->whereDate('date', $date->toDateString())->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        $holiday = DB::transaction(function () use ($workspace, $date, $name, $creditSeconds, $people, $actor) {
            $holiday = TimeHoliday::query()->forceCreate([
                'workspace_id' => $workspace->id,
                'date' => $date->toDateString(),
                'name' => $name,
                'credit_seconds' => $creditSeconds,
                'created_by' => $actor->id,
            ]);

            foreach ($people as $person) {
                if ($person->workspace_id !== $workspace->id) {
                    throw TimeTrackingException::invalid('Choose people on your team.');
                }
            }

            $holiday->people()->sync(array_map(fn (Person $person) => $person->id, $people));

            return $holiday;
        });

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::TIME_HOLIDAY_ADDED,
            "{$actor->name} added the holiday \"{$name}\" on {$date->format('M j, Y')} (".Durations::short($creditSeconds).' paid, '.($people === [] ? 'everyone' : count($people).' people').').',
            $holiday,
            ['date' => $date->toDateString(), 'credit_seconds' => $creditSeconds, 'people' => array_map(fn (Person $person) => $person->name, $people)],
        );

        return $holiday;
    }

    public function removeHoliday(TimeHoliday $holiday, User $actor): void
    {
        if (TimeHoliday::query()->whereKey($holiday->getKey())->whereNull('removed_at')->update(['removed_at' => now()]) === 1) {
            $this->auditLogger->handle($holiday->workspace, null, $actor, AuditAction::TIME_HOLIDAY_REMOVED, "{$actor->name} removed the holiday \"{$holiday->name}\".", $holiday, ['date' => $holiday->date->toDateString()]);
        }
    }

    public function addLeave(Person $person, CarbonImmutable $start, CarbonImmutable $end, LeaveType $type, int $creditSecondsPerDay, ?string $note, string $idempotencyKey, User $actor): TimeLeave
    {
        $existing = TimeLeave::query()->where('workspace_id', $person->workspace_id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($end->lessThan($start)) {
            throw TimeTrackingException::invalid('Leave must end on or after the day it starts.');
        }

        try {
            $leave = TimeLeave::query()->forceCreate([
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'type' => $type,
                'credit_seconds_per_day' => $type === LeaveType::Paid ? $creditSecondsPerDay : 0,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return TimeLeave::query()->where('workspace_id', $person->workspace_id)->where('idempotency_key', $idempotencyKey)->first() ?? throw $exception;
        }

        $this->auditLogger->handle(
            $person->workspace,
            null,
            $actor,
            AuditAction::TIME_LEAVE_ADDED,
            "{$actor->name} added {$type->value} leave for {$person->name}, {$start->format('M j')}–{$end->format('M j')}.",
            $leave,
            ['person' => $person->name, 'type' => $type->value, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'credit_seconds_per_day' => $leave->credit_seconds_per_day],
        );

        return $leave;
    }

    public function removeLeave(TimeLeave $leave, User $actor): void
    {
        if (TimeLeave::query()->whereKey($leave->getKey())->whereNull('removed_at')->update(['removed_at' => now()]) === 1) {
            $this->auditLogger->handle($leave->person->workspace, null, $actor, AuditAction::TIME_LEAVE_REMOVED, "{$actor->name} removed leave for {$leave->person->name}.", $leave, ['person' => $leave->person->name]);
        }
    }
}
