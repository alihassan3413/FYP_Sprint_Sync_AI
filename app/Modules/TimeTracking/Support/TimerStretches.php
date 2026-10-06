<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\TimeTracking\Models\TimeSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The running stretches of a timer (start and end only), written when each
 * one ends. They let SprintSync compare real work with the expected schedule
 * afterwards. Nothing about what the person was doing is stored.
 */
final class TimerStretches
{
    public static function record(TimeSession $session, ?CarbonInterface $from, CarbonInterface $to): void
    {
        if ($from === null || $to->lessThanOrEqualTo($from)) {
            return;
        }

        DB::table('time_session_segments')->insert([
            'time_session_id' => $session->id,
            'started_at' => $from->copy()->utc(),
            'ended_at' => $to->copy()->utc(),
            'seconds' => (int) $from->diffInSeconds($to),
        ]);
    }

    /**
     * @return list<array{0: CarbonInterface, 1: CarbonInterface}>
     */
    public static function for(TimeSession $session): array
    {
        return DB::table('time_session_segments')
            ->where('time_session_id', $session->id)
            ->orderBy('started_at')
            ->get(['started_at', 'ended_at'])
            ->map(fn (object $row) => [CarbonImmutable::parse($row->started_at, 'UTC'), CarbonImmutable::parse($row->ended_at, 'UTC')])
            ->all();
    }
}
