<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Pauses the person's timer: the running stretch is added to
 * accumulated_seconds by a conditional update from "running", so two pauses
 * (or a retry) count it once. Pausing a paused timer changes nothing.
 * Not audited: pause and resume would only add noise; the duration is kept.
 */
final class PauseTimerAction
{
    public function __construct(private readonly TimerOperationLog $operations) {}

    public function handle(Person $person, string $operationKey): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'pause')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey) {
                $session = TimeSession::query()->where('active_person_id', $person->id)->first() ?? throw TimeTrackingException::noActiveTimer();
                $now = now();
                $stretch = $session->runningSeconds($now);

                TimeSession::query()
                    ->whereKey($session->getKey())
                    ->where('state', TimerState::Running->value)
                    ->update([
                        'state' => TimerState::Paused->value,
                        'accumulated_seconds' => $session->accumulated_seconds + $stretch,
                        'longest_segment_seconds' => max($session->longest_segment_seconds, $stretch),
                        'segment_started_at' => null,
                        'updated_at' => $now,
                    ]);

                $this->operations->record($person, $operationKey, 'pause', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'pause') ?? throw $exception;
        }
    }
}
