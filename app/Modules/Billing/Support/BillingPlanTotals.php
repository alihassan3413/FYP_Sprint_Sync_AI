<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\InvoiceTotals;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\BillingPlan;

/**
 * The monthly figures of a fixed recurring invoice, worked out by
 * InvoiceCalculator exactly as a generated invoice will be. Hourly plans have
 * no total until a period's hours are known, so they return null.
 */
final class BillingPlanTotals
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    public function forPlan(BillingPlan $plan): ?InvoiceTotals
    {
        if ($plan->pricing_mode !== PricingMode::Fixed) {
            return null;
        }

        return $this->calculator->calculate(
            $plan->currency,
            $plan->lines->map(fn ($line) => ['quantity' => 100, 'unit_price' => $line->unit_price_minor])->values()->all(),
            $plan->adjustments->map(fn ($adjustment) => [
                'kind' => $adjustment->kind,
                'type' => $adjustment->type,
                'value' => $adjustment->value,
            ])->values()->all(),
        );
    }
}
