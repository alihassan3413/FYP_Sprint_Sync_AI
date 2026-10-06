<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActiveTimer;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use App\Modules\TimeTracking\Support\TimerStretches;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Running → lunch (a paid break, not a pause). Only when the workspace's
 * policy has paid lunch on at that moment. The running stretch is counted as
 * work up to the moment lunch starts; idle detection doesn't apply on lunch.
 * Same operation-key replay and occurred_at rules as the other timer changes.
 */
final class StartLunchAction
{
    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly TimePolicies $policies,
    ) {}

    public function handle(Person $person, string $operationKey, ?string $sessionPublicId = null, ?CarbonImmutable $occurredAt = null): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'lunch_start')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey, $sessionPublicId, $occurredAt) {
                $session = ActiveTimer::for($person, $sessionPublicId);
                $at = ActiveTimer::moment($session, $occurredAt);

                if (! $this->policies->at($person->workspace_id, $at)->lunchEnabled) {
                    throw TimeTrackingException::lunchNotEnabled();
                }

                if ($session->state === TimerState::Paused) {
                    throw TimeTrackingException::wrongState('Resume the timer before starting lunch.');
                }

                $stretch = $session->runningSeconds($at);
                $started = TimeSession::query()
                    ->whereKey($session->getKey())
                    ->where('state', TimerState::Running->value)
                    ->update([
                        'state' => TimerState::Lunch->value,
                        'accumulated_seconds' => $session->accumulated_seconds + $stretch,
                        'longest_segment_seconds' => max($session->longest_segment_seconds, $stretch),
                        'segment_started_at' => null,
                        'lunch_started_at' => $at,
                        'state_changed_at' => $at,
                        'updated_at' => now(),
                    ]);

                if ($started === 1) {
                    TimerStretches::record($session, $session->segment_started_at, $at);
                }

                $this->operations->record($person, $operationKey, 'lunch_start', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'lunch_start') ?? throw $exception;
        }
    }
}
