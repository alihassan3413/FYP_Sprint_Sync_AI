<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeSession;
use Illuminate\Support\Facades\DB;

/**
 * The record of timer operations by their client-generated keys. A key seen
 * before returns the timer it produced (the request is a retry); a key reused
 * for another person or another action is refused, never replayed.
 */
final class TimerOperationLog
{
    public function replay(Person $person, string $key, string $action): ?TimeSession
    {
        $operation = DB::table('timer_operations')
            ->where('workspace_id', $person->workspace_id)
            ->where('operation_key', $key)
            ->first();

        if ($operation === null) {
            return null;
        }

        if ((int) $operation->person_id !== $person->id || $operation->action !== $action) {
            throw TimeTrackingException::operationConflict();
        }

        return TimeSession::query()->findOrFail($operation->time_session_id);
    }

    /** Stored in the caller's transaction; a duplicate key throws, and the caller replays. */
    public function record(Person $person, string $key, string $action, TimeSession $session): void
    {
        DB::table('timer_operations')->insert([
            'workspace_id' => $person->workspace_id,
            'operation_key' => $key,
            'action' => $action,
            'time_session_id' => $session->id,
            'person_id' => $person->id,
            'created_at' => now(),
        ]);
    }
}
