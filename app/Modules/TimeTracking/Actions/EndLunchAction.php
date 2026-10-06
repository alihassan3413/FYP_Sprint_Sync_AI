<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimerState;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\ActiveTimer;
use App\Modules\TimeTracking\Support\LunchBreaks;
use App\Modules\TimeTracking\Support\TimerOperationLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lunch → running. The break becomes a paid-lunch entry (LunchBreaks:
 * allowance paid, any excess flagged for the owner) and work resumes from
 * the same moment. A second End lunch changes nothing.
 */
final class EndLunchAction
{
    public function __construct(
        private readonly TimerOperationLog $operations,
        private readonly LunchBreaks $lunches,
    ) {}

    public function handle(Person $person, string $operationKey, ?string $sessionPublicId = null, ?CarbonImmutable $occurredAt = null): TimeSession
    {
        if ($replayed = $this->operations->replay($person, $operationKey, 'lunch_end')) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($person, $operationKey, $sessionPublicId, $occurredAt) {
                $session = ActiveTimer::for($person, $sessionPublicId);
                $at = ActiveTimer::moment($session, $occurredAt);

                $ended = TimeSession::query()
                    ->whereKey($session->getKey())
                    ->where('state', TimerState::Lunch->value)
                    ->update([
                        'state' => TimerState::Running->value,
                        'segment_started_at' => $at,
                        'lunch_started_at' => null,
                        'state_changed_at' => $at,
                        'updated_at' => now(),
                    ]);

                if ($ended === 1) {
                    $this->lunches->close($session, $at);
                }

                $this->operations->record($person, $operationKey, 'lunch_end', $session);

                return $session->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->operations->replay($person, $operationKey, 'lunch_end') ?? throw $exception;
        }
    }
}
