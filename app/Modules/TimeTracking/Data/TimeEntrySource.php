<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where recorded time came from.
 *
 * - Tracked: a timer (real start and stop times). Never edited afterwards.
 * - Manual: a person's own time for one day.
 * - Adjustment: the owner's signed correction to an entry, or a historical
 *   total for a period ("48h, Oct 1–9, before tracking started").
 * - IdleKept: idle time the person kept as work, with a reason.
 * - Lunch: a paid lunch break. Paid time, never worked or billable time.
 */
#[TypeScript]
enum TimeEntrySource: string
{
    case Tracked = 'tracked';
    case Manual = 'manual';
    case Adjustment = 'adjustment';
    case IdleKept = 'idle_kept';
    case Lunch = 'lunch';

    /** Sources that are work (count toward worked and, when billable, client time). */
    public const WORK = ['tracked', 'manual', 'adjustment', 'idle_kept'];

    public function label(): string
    {
        return match ($this) {
            self::Tracked => 'Tracked',
            self::Manual => 'Added manually',
            self::Adjustment => 'Adjustment',
            self::IdleKept => 'Idle time kept as work',
            self::Lunch => 'Paid lunch',
        };
    }
}
