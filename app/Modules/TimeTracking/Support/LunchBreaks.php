<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;

/**
 * Closes a lunch break into a paid-lunch entry.
 *
 * Paid lunch is paid AND billable time (when the timer was billable), but not
 * worked time: it stays its own category. The allowance (the policy in force
 * when lunch started) is per workday: the first allowance-worth of lunch in
 * a workday is paid and billable; anything beyond is recorded as excess and
 * sent to the owner to decide. It is neither counted nor dropped silently.
 *
 * The workday is the session's (Schedules::workdayOf), not the calendar
 * date lunch started: on an 18:00–03:00 shift a 00:30 lunch uses that
 * evening's allowance, and the next evening's lunch gets a fresh one.
 */
final class LunchBreaks
{
    public function __construct(
        private readonly TimePolicies $policies,
        private readonly Schedules $schedules,
    ) {}

    public function close(TimeSession $session, CarbonImmutable $at): ?TimeEntry
    {
        if ($session->lunch_started_at === null) {
            return null;
        }

        $started = CarbonImmutable::instance($session->lunch_started_at);
        $seconds = max(0, $at->getTimestamp() - $started->getTimestamp());
        $workDate = $this->schedules->workdayOf($session);
        $allowance = (int) $this->policies->at($session->workspace_id, $started)->lunchAllowanceSeconds;

        $paidSoFar = (int) TimeEntry::query()
            ->active()
            ->where('person_id', $session->person_id)
            ->where('source', TimeEntrySource::Lunch->value)
            ->whereNull('adjusts_entry_id')
            ->whereDate('period_start', $workDate)
            ->sum('paid_seconds');

        $paid = min($seconds, max(0, $allowance - $paidSoFar));
        $excess = $seconds - $paid;

        $entry = TimeEntry::query()->forceCreate([
            'workspace_id' => $session->workspace_id,
            'person_id' => $session->person_id,
            'project_id' => $session->project_id,
            'task_id' => $session->task_id,
            'project_name' => $session->project_name,
            'task_title' => $session->task_title,
            'source' => TimeEntrySource::Lunch,
            'from_session_id' => $session->id,
            'started_at' => $started,
            'ended_at' => $at,
            'duration_seconds' => $seconds,
            'paid_seconds' => $paid,
            'period_start' => $workDate,
            'period_end' => $workDate,
            'timezone' => $session->timezone,
            'billable' => $session->billable,
            'created_by' => $session->started_by,
        ]);

        if ($excess > 0) {
            TimeReview::query()->forceCreate([
                'workspace_id' => $session->workspace_id,
                'person_id' => $session->person_id,
                'kind' => ReviewKind::LunchExcess,
                'severity' => 'info',
                'work_date' => $workDate,
                'subject_key' => "lunch:{$entry->id}",
                'seconds' => $excess,
                'summary' => 'Lunch ran '.Durations::short($excess).' past the paid allowance.',
                'details' => ['lunch_seconds' => $seconds, 'paid_seconds' => $paid, 'allowance_seconds' => $allowance, 'entry' => $entry->public_id],
            ]);
        }

        return $entry;
    }
}
