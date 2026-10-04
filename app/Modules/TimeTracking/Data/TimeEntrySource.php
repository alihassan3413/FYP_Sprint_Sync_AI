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
 */
#[TypeScript]
enum TimeEntrySource: string
{
    case Tracked = 'tracked';
    case Manual = 'manual';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Tracked => 'Tracked',
            self::Manual => 'Added manually',
            self::Adjustment => 'Adjustment',
        };
    }
}
