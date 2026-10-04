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
 * Resumes a paused timer with a new running stretch from now. A conditional
 * update from "paused" means a second resume cannot restart the stretch (and
 * so cannot lose or double any time). Resuming a running timer changes nothing.
 */
final class ResumeTimerAction
{
    public function __construct(private readonly TimerOperationLog $operations) {}

    public function handle(Person $person, string $operationKey): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'resume')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey) {
                $session = TimeSession::query()->where('active_person_id', $person->id)->first() ?? throw TimeTrackingException::noActiveTimer();

                TimeSession::query()
                    ->whereKey($session->getKey())
                    ->where('state', TimerState::Paused->value)
                    ->update(['state' => TimerState::Running->value, 'segment_started_at' => now(), 'updated_at' => now()]);

                $this->operations->record($person, $operationKey, 'resume', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'resume') ?? throw $exception;
        }
    }
}
