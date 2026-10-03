<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Fixed: every line is billed once per period at its amount.
 * Hourly: every line is a team member billed per hour; the hours arrive each
 * period (typed in, or from time tracking) and are never part of the plan.
 */
#[TypeScript]
enum PricingMode: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
}
