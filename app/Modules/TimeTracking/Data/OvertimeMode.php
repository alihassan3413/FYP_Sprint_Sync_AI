<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What happens to time above the weekly target. v1 never pays, converts or
 * blocks anything: it is tracked, and in "review" mode highlighted for the
 * owner. Paid overtime and time-off credit come with compensation later.
 */
#[TypeScript]
enum OvertimeMode: string
{
    case TrackOnly = 'track_only';
    case Review = 'review';
}
