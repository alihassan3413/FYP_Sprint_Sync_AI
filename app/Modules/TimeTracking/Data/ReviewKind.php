<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the owner is asked to look at. Never an accusation: activity reviews
 * only say a pattern was unusual and a person should look.
 *
 * - LunchExcess: lunch ran past the paid allowance (count it as paid and
 *   billable, or don't count it).
 * - IdleKept: idle time someone kept as work, with their reason (accept or reject).
 * - Activity: activity looked unusually repetitive (no concern, or follow up).
 */
#[TypeScript]
enum ReviewKind: string
{
    case LunchExcess = 'lunch_excess';
    case IdleKept = 'idle_kept';
    case Activity = 'activity';

    /** The decisions the owner can make for this kind. */
    public function decisions(): array
    {
        return match ($this) {
            self::LunchExcess => ['counted', 'not_counted'],
            self::IdleKept => ['accepted', 'rejected'],
            self::Activity => ['no_concern', 'follow_up'],
        };
    }
}
