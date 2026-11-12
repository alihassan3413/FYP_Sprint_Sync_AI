<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\Audit\Data\SystemActor;
use App\Modules\TimeTracking\Data\TimerStopReason;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\Schedules;
use App\Modules\TimeTracking\Support\TimePolicies;
use Carbon\CarbonImmutable;

final class StopAbandonedTimerAction
{
    public function __construct(
        private readonly StopTimerAction $stop,
        private readonly Schedules $schedules,
        private readonly TimePolicies $policies,
    ) {}

    public function handle(TimeSession $session, ?CarbonImmutable $now = null): ?TimeSession
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $this->cutoff($session, $now);

        if ($cutoff === null || $cutoff['at']->greaterThan($now)) {
            return null;
        }

        try {
            $stopped = $this->stop->handle($session->person, "auto-stop:{$session->public_id}", new SystemActor, $session->public_id, $cutoff['at'], $cutoff['reason']);
        } catch (TimeTrackingException) {
            return null;
        }

        return $stopped->stop_reason === $cutoff['reason'] ? $stopped : null;
    }

    /**
     * @return array{at: CarbonImmutable, reason: TimerStopReason}|null
     */
    public function cutoff(TimeSession $session, CarbonImmutable $now): ?array
    {
        if (! $session->state->isActive()) {
            return null;
        }

        $policy = $this->policies->at($session->workspace_id, $now);
        $lastChange = CarbonImmutable::instance($session->state_changed_at ?? $session->started_at);
        $candidates = [];

        if ($policy->autoStopAfterShiftSeconds !== null && ($shiftEnd = $this->schedules->shiftEnd($session)) !== null) {
            $candidates[] = ['at' => $shiftEnd->max($lastChange)->addSeconds($policy->autoStopAfterShiftSeconds), 'reason' => TimerStopReason::ShiftEnded];
        }

        if ($policy->maxTimerSeconds !== null) {
            $candidates[] = ['at' => $lastChange->addSeconds($policy->maxTimerSeconds), 'reason' => TimerStopReason::MaxDuration];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $a['at'] <=> $b['at']);
        $earliest = $candidates[0];
        $effectiveFrom = CarbonImmutable::instance($policy->effectiveFrom ?? $now)->utc();

        return ['at' => $earliest['at']->max($effectiveFrom)->utc(), 'reason' => $earliest['reason']];
    }
}
