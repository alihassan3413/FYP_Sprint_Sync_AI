<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;

/**
 * Finds the person's active timer for pause / resume / stop and decides when
 * the change happened.
 *
 * - When the client names the timer it means (the desktop app always does)
 *   and that is not the active one, the change is refused as "changed on
 *   another device" rather than applied to a different timer.
 * - A change synced late (occurred_at) may not be earlier than the timer's
 *   last change, so offline changes apply in order and never rewrite what
 *   already happened. Without occurred_at the server clock is used.
 */
final class ActiveTimer
{
    public static function for(Person $person, ?string $sessionPublicId): TimeSession
    {
        $session = TimeSession::query()->where('active_person_id', $person->id)->first();

        if ($sessionPublicId !== null && $session?->public_id !== $sessionPublicId) {
            throw TimeTrackingException::timerChanged();
        }

        return $session ?? throw TimeTrackingException::noActiveTimer();
    }

    public static function moment(TimeSession $session, ?CarbonImmutable $occurredAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($occurredAt === null) {
            return $now;
        }

        $lastChange = $session->state_changed_at ?? $session->started_at;

        if ($occurredAt->lessThan($lastChange)) {
            throw TimeTrackingException::timerChanged();
        }

        return $occurredAt->greaterThan($now) ? $now : $occurredAt;
    }
}
