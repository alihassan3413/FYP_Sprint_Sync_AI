<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActiveTimer;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use App\Modules\TimeTracking\Support\TimerStretches;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * SprintSync Desktop's automatic pause after inactivity.
 *
 * The client reports two moments: when the computer was last used
 * ($idleSince) and when its idle time reached the threshold ($detectedAt).
 * Work stops counting at the last activity, not at detection, and the idle
 * period is kept on record (the person can later keep it as work, with a
 * reason). The server checks the event against the policy in force at that
 * moment: idle detection must be on and the reported idle time must reach
 * the server's threshold (a few seconds of polling slack allowed). The
 * threshold always comes from the server; there is nothing to change locally.
 *
 * Only a running timer is paused; on lunch or already paused nothing changes.
 */
final class IdlePauseTimerAction
{
    /** Desktop polls the OS idle time every few seconds. */
    public const SLACK_SECONDS = 10;

    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly TimePolicies $policies,
    ) {}

    public function handle(Person $person, string $operationKey, ?string $sessionPublicId, CarbonImmutable $idleSince, ?CarbonImmutable $detectedAt = null): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'idle_pause')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey, $sessionPublicId, $idleSince, $detectedAt) {
                $session = ActiveTimer::for($person, $sessionPublicId);
                $detected = ActiveTimer::moment($session, $detectedAt);
                $policy = $this->policies->at($person->workspace_id, $idleSince);

                if (! $policy->idleEnabled) {
                    throw TimeTrackingException::invalid('Idle detection is off for this workspace.');
                }

                /* Idle can't start before this stretch of work began. */
                $lastChange = CarbonImmutable::instance($session->state_changed_at ?? $session->started_at);
                $since = $idleSince->lessThan($lastChange) ? $lastChange : $idleSince;

                if ($detected->getTimestamp() - $since->getTimestamp() < (int) $policy->idleSeconds - self::SLACK_SECONDS) {
                    throw TimeTrackingException::invalid('That inactivity is shorter than the workspace\'s idle time.');
                }

                if ($session->state === TimerState::Running) {
                    $stretch = $session->runningSeconds($since);
                    $paused = TimeSession::query()
                        ->whereKey($session->getKey())
                        ->where('state', TimerState::Running->value)
                        ->update([
                            'state' => TimerState::Paused->value,
                            'pause_reason' => 'idle',
                            'accumulated_seconds' => $session->accumulated_seconds + $stretch,
                            'longest_segment_seconds' => max($session->longest_segment_seconds, $stretch),
                            'segment_started_at' => null,
                            'state_changed_at' => $since,
                            'updated_at' => now(),
                        ]);

                    if ($paused === 1) {
                        TimerStretches::record($session, $session->segment_started_at, $since);
                        TimeIdlePeriod::query()->forceCreate([
                            'workspace_id' => $person->workspace_id,
                            'person_id' => $person->id,
                            'time_session_id' => $session->id,
                            'started_at' => $since,
                            'detected_at' => $detected,
                            'threshold_seconds' => (int) $policy->idleSeconds,
                        ]);
                    }
                }

                $this->operations->record($person, $operationKey, 'idle_pause', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'idle_pause') ?? throw $exception;
        }
    }
}
