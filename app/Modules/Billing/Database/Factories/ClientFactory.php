<?php

declare(strict_types=1);

namespace App\Modules\Billing\Database\Factories;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
final class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->company(),
            'billing_email' => fake()->unique()->companyEmail(),
            'cc_emails' => null,
            'currency' => Currency::USD,
            'address' => null,
            'tax_id' => null,
        ];
    }

    /**
     * The first client SprintSync invoices: billed in USD, invoices to billing@.
     */
    public function rocketFlood(): static
    {
        return $this->state(fn () => [
            'name' => 'RocketFlood',
            'billing_email' => 'billing@rocketflood.com',
            'currency' => Currency::USD,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
