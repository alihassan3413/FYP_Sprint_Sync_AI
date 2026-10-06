<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Controllers;

use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Data\TimerData;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\TimeTracking\Models\TimeSession;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\TimeTracking\Support\TimePolicies;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the desktop timer needs in one call: whether the account has a
 * Team profile here, the projects and open tasks it may track (public ids
 * only), the current timer and today's recorded total from TimeTotals.
 */
final class DesktopTimeController
{
    public function __invoke(Request $request, Workspace $workspace, TimeAccess $access, TimeTotals $totals, TimePolicies $policies): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('track', [TimeEntry::class, $workspace]), 403);

        $person = $access->personFor($user, $workspace);
        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();
        $session = $person === null ? null : TimeSession::query()->where('active_person_id', $person->id)->first();

        return response()->json([
            'has_profile' => $person !== null,
            'projects' => $person === null ? [] : TimeIndexController::projectOptions($access->projects($user, $workspace)),
            'timer' => $session === null ? null : TimerData::fromSession($session, now()),
            'today_seconds' => $person === null ? 0 : $totals->seconds(new TimeQuery($workspace->id, personId: $person->id, from: $today, to: $today)),
            'today' => $today->toDateString(),
            'timezone' => $workspace->timezone,
            'server_time' => now()->toIso8601String(),
            /* The rules the app must follow (idle threshold, lunch) come from here, never from local settings. */
            'policy' => $policies->at($workspace->id)->toArray(),
            'today_breakdown' => $person === null ? null : $totals->breakdown($person, $today, $today)->toArray(),
            'idle_periods' => $person === null ? [] : array_values(array_filter(TimeIndexController::idlePeriods($person->id), fn (array $period) => $period['decision'] === 'pending')),
        ]);
    }
}
