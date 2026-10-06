<?php

declare(strict_types=1);

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPlan>
 */
final class BillingPlanFactory extends Factory
{
    protected $model = BillingPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'workspace_id' => fn (array $attributes) => Client::query()->whereKey($attributes['client_id'])->value('workspace_id'),
            'name' => fake()->unique()->words(2, true),
            'currency' => Currency::USD,
            'pricing_mode' => PricingMode::Fixed,
            'generation_day' => 1,
            'send_day' => 1,
            'billing_period' => BillingPeriod::PreviousMonth,
            'due_in_days' => 15,
            'delivery_mode' => DeliveryMode::ReviewBeforeSending,
            'reminder_days_before' => DeliveryMode::DEFAULT_REMINDER_DAYS,
        ];
    }

    public function hourly(): static
    {
        return $this->state(fn () => [
            'pricing_mode' => PricingMode::Hourly,
            'generation_day' => 1,
            'send_day' => 3,
            'billing_period' => BillingPeriod::PreviousMonth,
            'reminder_days_before' => null,
        ]);
    }

    public function autoSend(): static
    {
        return $this->state(fn () => ['delivery_mode' => DeliveryMode::AutoSend, 'reminder_days_before' => null]);
    }
}
