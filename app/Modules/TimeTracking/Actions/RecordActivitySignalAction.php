<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\SystemActor;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\ReviewKind;
use App\Modules\TimeTracking\Models\TimeReview;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActivityPattern;
use App\Modules\TimeTracking\Support\Schedules;
use App\Modules\TimeTracking\Support\TimePolicies;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Receives one window of activity-pattern numbers from SprintSync Desktop.
 * Only when the workspace has activity integrity on (when the window ended), and only
 * when ActivityPattern finds it unusual, is anything kept: one review for the
 * owner (once per person and window, however often it is sent). Ordinary
 * windows are evaluated and forgotten; nothing is logged per window.
 */
final class RecordActivitySignalAction
{
    public function __construct(
        private readonly TimePolicies $policies,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly Schedules $schedules,
    ) {}

    public function handle(Person $person, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $resets, int $meanGapMs, int $variationBasisPoints): ?TimeReview
    {
        if (! $this->policies->at($person->workspace_id, $windowEnd)->integrityEnabled) {
            return null;
        }

        $finding = ActivityPattern::evaluate($resets, $meanGapMs, $variationBasisPoints);

        if ($finding === null) {
            return null;
        }

        $key = "activity:{$person->id}:{$windowStart->utc()->format('YmdHi')}";

        try {
            $review = TimeReview::query()->forceCreate([
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'kind' => ReviewKind::Activity,
                'severity' => 'review',
                'work_date' => $this->workDate($person, $windowStart, $windowEnd),
                'subject_key' => $key,
                'seconds' => max(0, $windowEnd->getTimestamp() - $windowStart->getTimestamp()),
                'summary' => $finding['summary'],
                'details' => [
                    'window_start' => $windowStart->toIso8601String(),
                    'window_end' => $windowEnd->toIso8601String(),
                    'resets' => $resets,
                    'mean_gap_seconds' => round($meanGapMs / 1000, 1),
                    'variation_percent' => round($variationBasisPoints / 100, 2),
                    'confidence' => $finding['confidence'],
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            return TimeReview::query()->where('workspace_id', $person->workspace_id)->where('subject_key', $key)->first();
        }

        $this->auditLogger->handle(
            $person->workspace,
            null,
            new SystemActor,
            AuditAction::TIME_ACTIVITY_FLAGGED,
            "Activity for {$person->name} looked unusually repetitive. Review recommended.",
            $review,
            ['person' => $person->name, 'confidence' => $finding['confidence']],
        );

        return $review;
    }

    /** The workday of the session the window fell in; with no session, the workday of the moment itself. */
    private function workDate(Person $person, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): string
    {
        $session = TimeSession::query()
            ->where('person_id', $person->id)
            ->where('started_at', '<=', $windowEnd)
            ->where(fn ($query) => $query->whereNull('stopped_at')->orWhere('stopped_at', '>=', $windowStart))
            ->latest('started_at')
            ->first();

        return $session !== null ? $this->schedules->workdayOf($session) : $this->schedules->workday($person, $windowStart, $person->workspace->timezone);
    }
}
