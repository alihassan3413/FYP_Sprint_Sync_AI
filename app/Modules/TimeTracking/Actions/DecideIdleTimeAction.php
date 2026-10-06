<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\Durations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The person decides what an idle period was:
 *
 * - keep: it was work (a phone call, reading on paper). A reason is required;
 *   the time is added back as its own "kept as work" entry (the detection
 *   stays on record) and the owner gets it to review;
 * - drop: it wasn't work. Nothing is added.
 *
 * If the timer is still paused for idle, keeping it resumes the timer from
 * now, so the period has an end. A decision is made once (conditional update
 * from "pending"), so a retry or double click changes nothing.
 */
final class DecideIdleTimeAction
{
    public function __construct(
        private readonly ResumeTimerAction $resume,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function keep(Person $person, TimeIdlePeriod $period, string $reason, User $actor): TimeIdlePeriod
    {
        $this->closeIfOpen($person, $period);
        $period->refresh();

        if ($period->decision !== 'pending') {
            return $period;
        }

        DB::transaction(function () use ($person, $period, $reason, $actor) {
            $decided = TimeIdlePeriod::query()->whereKey($period->getKey())->where('decision', 'pending')->update(['decision' => 'kept', 'reason' => $reason, 'decided_at' => now(), 'updated_at' => now()]);

            if ($decided !== 1) {
                return;
            }

            /** @var TimeSession $session */
            $session = $period->session;
            $seconds = $period->seconds();
            $workDate = $period->started_at->copy()->setTimezone($session->timezone)->toDateString();

            $entry = TimeEntry::query()->forceCreate([
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'project_id' => $session->project_id,
                'task_id' => $session->task_id,
                'project_name' => $session->project_name,
                'task_title' => $session->task_title,
                'source' => TimeEntrySource::IdleKept,
                'from_session_id' => $session->id,
                'started_at' => $period->started_at,
                'ended_at' => $period->ended_at,
                'duration_seconds' => $seconds,
                'period_start' => $workDate,
                'period_end' => $workDate,
                'timezone' => $session->timezone,
                'billable' => $session->billable,
                'note' => $reason,
                'created_by' => $actor->id,
            ]);

            TimeIdlePeriod::query()->whereKey($period->getKey())->update(['kept_entry_id' => $entry->id]);

            TimeReview::query()->forceCreate([
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'kind' => ReviewKind::IdleKept,
                'severity' => 'info',
                'work_date' => $workDate,
                'subject_key' => "idle:{$period->id}",
                'seconds' => $seconds,
                'summary' => Durations::short($seconds)." of idle time kept as work: {$reason}",
                'details' => ['idle_period' => $period->public_id, 'entry' => $entry->public_id, 'threshold_seconds' => $period->threshold_seconds],
            ]);

            $this->auditLogger->handle(
                $person->workspace,
                null,
                $actor,
                AuditAction::TIME_IDLE_KEPT,
                "{$actor->name} kept ".Durations::short($seconds)." of idle time as work for {$person->name}: {$reason}",
                $entry,
                ['person' => $person->name, 'seconds' => $seconds, 'reason' => $reason],
            );
        });

        return $period->refresh();
    }

    public function drop(Person $person, TimeIdlePeriod $period): TimeIdlePeriod
    {
        $this->closeIfOpen($person, $period);
        TimeIdlePeriod::query()->whereKey($period->getKey())->where('decision', 'pending')->update(['decision' => 'discarded', 'decided_at' => now(), 'updated_at' => now()]);

        return $period->refresh();
    }

    private function closeIfOpen(Person $person, TimeIdlePeriod $period): void
    {
        if ($period->person_id !== $person->id) {
            throw TimeTrackingException::invalid('That idle time belongs to someone else.');
        }

        if ($period->ended_at === null) {
            $this->resume->handle($person, 'idle-'.$period->public_id.'-'.Str::lower((string) Str::ulid()), $period->session->public_id);
        }
    }
}
