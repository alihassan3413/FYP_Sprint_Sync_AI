<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Modules\TimeTracking\Data\PolicySnapshot;
use App\Modules\TimeTracking\Models\TimePolicy;
use Carbon\CarbonInterface;

/**
 * Which version of the time rules applied at a moment: the latest one saved
 * on or before it. Past time is always judged by the rules of its own time;
 * a change today never reinterprets October.
 */
final class TimePolicies
{
    public function at(int $workspaceId, ?CarbonInterface $at = null): PolicySnapshot
    {
        return PolicySnapshot::fromModel(
            TimePolicy::query()
                ->where('workspace_id', $workspaceId)
                ->where('effective_from', '<=', ($at ?? now())->copy()->utc())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first(),
        );
    }
}
