<?php

declare(strict_types=1);

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare invoice row for tests that only need one to exist. Real invoices are
 * built by GenerateInvoiceFromPlanAction.
 *
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'workspace_id' => fn (array $attributes) => Client::query()->whereKey($attributes['client_id'])->value('workspace_id'),
            'status' => InvoiceStatus::ReadyToReview,
            'version' => 1,
            'title' => 'Monthly services',
            'pricing_mode' => PricingMode::Fixed,
            'delivery_mode' => DeliveryMode::ReviewBeforeSending,
            'currency' => Currency::USD,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'generated_on' => '2026-10-01',
            'planned_send_on' => '2026-10-01',
            'due_in_days' => 15,
            'bill_to' => ['name' => 'RocketFlood', 'billing_email' => 'billing@rocketflood.com', 'cc_emails' => [], 'address' => null, 'tax_id' => null],
        ];
    }
}
