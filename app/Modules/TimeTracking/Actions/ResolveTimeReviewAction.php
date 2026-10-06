<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeReview;

/**
 * The owner's decision on a review, made once:
 *
 * - lunch excess: "counted" adds the excess as paid and billable lunch (its
 *   own entry, linked to the lunch, so nothing is edited); "not counted"
 *   leaves only the allowance counted;
 * - idle kept as work: "accepted" keeps it; "rejected" voids the kept entry;
 * - activity: "no concern" or "follow up". Either clears the hold on
 *   automatic invoices; nothing is ever deducted automatically.
 */
final class ResolveTimeReviewAction
{
    public function __construct(
        private readonly VoidTimeEntryAction $void,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(TimeReview $review, string $decision, ?string $note, User $actor): TimeReview
    {
        if (! in_array($decision, $review->kind->decisions(), true)) {
            throw TimeTrackingException::invalid('Choose one of the options for this review.');
        }

        $resolved = TimeReview::query()->whereKey($review->getKey())->where('status', 'open')->update([
            'status' => 'resolved',
            'decision' => $decision,
            'note' => $note,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $review->refresh();

        if ($resolved !== 1) {
            return $review;
        }

        if ($review->kind === ReviewKind::LunchExcess && $decision === 'counted') {
            $this->countExcessLunch($review, $actor);
        }

        if ($review->kind === ReviewKind::IdleKept && $decision === 'rejected') {
            $entry = TimeEntry::query()->where('public_id', $review->details['entry'] ?? null)->first();

            if ($entry !== null && ! $entry->isVoided()) {
                $this->void->handle($entry, 'Idle time not accepted'.($note ? ": {$note}" : '.'), $actor);
            }
        }

        $this->auditLogger->handle(
            $review->person->workspace,
            null,
            $actor,
            AuditAction::TIME_REVIEW_RESOLVED,
            "{$actor->name} reviewed \"{$review->summary}\" for {$review->person->name}: ".str_replace('_', ' ', $decision).'.',
            $review,
            ['kind' => $review->kind->value, 'decision' => $decision, 'note' => $note],
        );

        return $review;
    }

    private function countExcessLunch(TimeReview $review, User $actor): void
    {
        $lunch = TimeEntry::query()->where('public_id', $review->details['entry'] ?? null)->first();

        if ($lunch === null || $lunch->isVoided()) {
            return;
        }

        TimeEntry::query()->forceCreate([
            'workspace_id' => $lunch->workspace_id,
            'person_id' => $lunch->person_id,
            'project_id' => $lunch->project_id,
            'task_id' => $lunch->task_id,
            'project_name' => $lunch->project_name,
            'task_title' => $lunch->task_title,
            'source' => TimeEntrySource::Lunch,
            'from_session_id' => $lunch->from_session_id,
            'adjusts_entry_id' => $lunch->id,
            'duration_seconds' => 0,
            'paid_seconds' => (int) $review->seconds,
            'period_start' => $lunch->period_start->toDateString(),
            'period_end' => $lunch->period_end->toDateString(),
            'timezone' => $lunch->timezone,
            'billable' => $lunch->billable,
            'note' => 'Lunch past the allowance, counted by the owner',
            'created_by' => $actor->id,
        ]);
    }
}
