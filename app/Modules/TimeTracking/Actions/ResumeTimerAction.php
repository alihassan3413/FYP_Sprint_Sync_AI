<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Models\TimeIdlePeriod;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActiveTimer;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Resuming after an automatic idle pause also closes that idle period (it
 * ends when the person came back), ready for them to keep or drop.
 *
 * Resumes a paused timer with a new running stretch from now. A conditional
 * update from "paused" means a second resume cannot restart the stretch (and
 * so cannot lose or double any time). Resuming a running timer changes nothing.
 */
final class ResumeTimerAction
{
    public function __construct(private readonly TimerOperationLog $operations) {}

    public function handle(Person $person, string $operationKey, ?string $sessionPublicId = null, ?CarbonImmutable $occurredAt = null): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'resume')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey, $sessionPublicId, $occurredAt) {
                $session = ActiveTimer::for($person, $sessionPublicId);
                $at = ActiveTimer::moment($session, $occurredAt);

                $resumed = TimeSession::query()
                    ->whereKey($session->getKey())
                    ->where('state', TimerState::Paused->value)
                    ->update(['state' => TimerState::Running->value, 'segment_started_at' => $at, 'pause_reason' => null, 'state_changed_at' => $at, 'updated_at' => now()]);

                if ($resumed === 1 && $session->pause_reason === 'idle') {
                    TimeIdlePeriod::query()->where('time_session_id', $session->id)->whereNull('ended_at')->update(['ended_at' => $at, 'updated_at' => now()]);
                }

                $this->operations->record($person, $operationKey, 'resume', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'resume') ?? throw $exception;
        }
    }
}
