<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Support\BillingPlanTotals;
use App\Modules\Billing\Support\BillingSchedule;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A recurring invoice as the client page shows and edits it. Totals come from
 * InvoiceCalculator on the server (fixed plans only); the browser's live
 * preview is a convenience and the server's figures are authoritative.
 * Dates are calendar dates (Y-m-d) in the workspace's timezone.
 */
#[TypeScript]
final class BillingPlanData extends Data
{
    /**
     * @param  list<BillingPlanLineData>  $lines
     * @param  list<BillingPlanAdjustmentData>  $adjustments
     * @param  list<int>|null  $adjustment_amounts_minor
     */
    public function __construct(
        public string $public_id,
        public string $name,
        public string $currency,
        #[LiteralTypeScriptType("'fixed' | 'hourly'")]
        public string $pricing_mode,
        public int $generation_day,
        public int $send_day,
        #[LiteralTypeScriptType("'previous_month' | 'current_month'")]
        public string $billing_period,
        public int $due_in_days,
        #[LiteralTypeScriptType("'review_before_sending' | 'auto_send'")]
        public string $delivery_mode,
        public ?int $reminder_days_before,
        public bool $paused,
        public array $lines,
        public array $adjustments,
        public ?int $subtotal_minor,
        #[LiteralTypeScriptType('number[] | null')]
        public ?array $adjustment_amounts_minor,
        public ?int $total_minor,
        public string $next_generation_on,
        public string $next_send_on,
        public string $next_period_start,
        public string $next_period_end,
        public string $due_period_start,
        public ?string $due_invoice_public_id,
    ) {}

    /**
     * @param  string|null  $dueInvoicePublicId  the invoice already generated for the latest due month, if any
     */
    public static function fromModel(BillingPlan $plan, CarbonImmutable $today, ?string $dueInvoicePublicId = null): self
    {
        $schedule = app(BillingSchedule::class);
        $cycle = $schedule->nextCycleForPlan($plan, $today);
        $due = $schedule->latestDueCycle($plan, $today);
        $totals = app(BillingPlanTotals::class)->forPlan($plan);

        return new self(
            public_id: $plan->public_id,
            name: $plan->name,
            currency: $plan->currency->value,
            pricing_mode: $plan->pricing_mode->value,
            generation_day: $plan->generation_day,
            send_day: $plan->send_day,
            billing_period: $plan->billing_period->value,
            due_in_days: $plan->due_in_days,
            delivery_mode: $plan->delivery_mode->value,
            reminder_days_before: $plan->reminder_days_before,
            paused: $plan->isPaused(),
            lines: $plan->lines->map(fn ($line) => new BillingPlanLineData(
                person_public_id: $line->person?->public_id,
                description: $line->description,
                role_label: $line->role_label,
                unit_price_minor: $line->unit_price_minor,
            ))->values()->all(),
            adjustments: $plan->adjustments->map(fn ($adjustment) => new BillingPlanAdjustmentData(
                kind: $adjustment->kind->value,
                type: $adjustment->type->value,
                label: $adjustment->label,
                value: $adjustment->value,
            ))->values()->all(),
            subtotal_minor: $totals?->subtotal->minor,
            adjustment_amounts_minor: $totals === null ? null : array_map(fn ($amount) => $amount->minor, $totals->adjustments),
            total_minor: $totals?->total->minor,
            next_generation_on: $cycle->generationDate->toDateString(),
            next_send_on: $cycle->sendDate->toDateString(),
            next_period_start: $cycle->periodStart->toDateString(),
            next_period_end: $cycle->periodEnd->toDateString(),
            due_period_start: $due->periodStart->toDateString(),
            due_invoice_public_id: $dueInvoicePublicId,
        );
    }
}
