<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * A validated recurring invoice, every amount already an integer: line prices
 * in minor units, percentages in basis points. Positions follow list order.
 */
final readonly class StoreBillingPlanData
{
    /**
     * @param  list<array{person_id: int|null, description: string, role_label: string|null, unit_price_minor: int}>  $lines
     * @param  list<array{kind: AdjustmentKind, type: AdjustmentType, label: string, value: int}>  $adjustments
     */
    public function __construct(
        public string $name,
        public Currency $currency,
        public PricingMode $pricing_mode,
        public int $generation_day,
        public int $send_day,
        public BillingPeriod $billing_period,
        public int $due_in_days,
        public DeliveryMode $delivery_mode,
        public ?int $reminder_days_before,
        public array $lines,
        public array $adjustments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'currency' => $this->currency,
            'pricing_mode' => $this->pricing_mode,
            'generation_day' => $this->generation_day,
            'send_day' => $this->send_day,
            'billing_period' => $this->billing_period,
            'due_in_days' => $this->due_in_days,
            'delivery_mode' => $this->delivery_mode,
            'reminder_days_before' => $this->reminder_days_before,
        ];
    }
}
