<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use App\Models\User;
use App\Modules\Billing\Actions\SaveBillingPlanAction;
use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\AdjustmentType;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Data\StoreBillingPlanData;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;

/**
 * RocketFlood's two recurring invoices with the real numbers, built through
 * SaveBillingPlanAction exactly as the builder saves them.
 */
trait BuildsRocketFlood
{
    protected User $owner;

    protected Workspace $workspace;

    protected Client $rocketFlood;

    /** @var array<string, Person> */
    protected array $team = [];

    protected function setUpRocketFlood(): void
    {
        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->rocketFlood = Client::factory()->rocketFlood()->for($this->workspace)->create([
            'address' => "1200 Market Street\nSan Francisco, CA",
            'tax_id' => '12-3456789',
        ]);

        foreach ([
            'Ali Hassan' => 'Developer',
            'Aamir Sattar' => 'UI/UX Designer',
            'Azhar Hussain' => null,
            'Muhammad Usman Ghani' => 'CSR',
            'Abdul Wahab' => 'CSR',
            'Faheem' => 'CSR',
            'Abdul Rehman' => 'CSR',
            'Muhammad Faizan' => 'CSR',
        ] as $name => $title) {
            $this->team[$name] = Person::factory()->for($this->workspace)->create(['name' => $name, 'title' => $title]);
        }
    }

    protected function devAndOffice(): BillingPlan
    {
        $member = fn (string $name, int $minor) => ['person_id' => $this->team[$name]->id, 'description' => $name, 'role_label' => $this->team[$name]->title, 'unit_price_minor' => $minor];
        $item = fn (string $name, int $minor) => ['person_id' => null, 'description' => $name, 'role_label' => null, 'unit_price_minor' => $minor];

        return $this->savePlan('Dev & Office', PricingMode::Fixed, 1, 1, [
            $member('Ali Hassan', 200000),
            $member('Aamir Sattar', 120000),
            $member('Azhar Hussain', 30000),
            $item('Office Rent', 110000),
            $item('Office Boy', 18000),
            $item('Guard', 18000),
        ], [['kind' => AdjustmentKind::Fee, 'type' => AdjustmentType::Percentage, 'label' => 'Deel fee', 'value' => 200]]);
    }

    protected function csrTeam(): BillingPlan
    {
        $rate = fn (string $name, int $minor) => ['person_id' => $this->team[$name]->id, 'description' => $name, 'role_label' => 'CSR', 'unit_price_minor' => $minor];

        return $this->savePlan('CSR Team', PricingMode::Hourly, 1, 3, [
            $rate('Muhammad Usman Ghani', 555),
            $rate('Abdul Wahab', 455),
            $rate('Faheem', 496),
            $rate('Abdul Rehman', 496),
            $rate('Muhammad Faizan', 555),
        ], []);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $adjustments
     */
    private function savePlan(string $name, PricingMode $mode, int $generationDay, int $sendDay, array $lines, array $adjustments): BillingPlan
    {
        return app(SaveBillingPlanAction::class)->handle($this->rocketFlood, null, new StoreBillingPlanData(
            name: $name,
            currency: Currency::USD,
            pricing_mode: $mode,
            generation_day: $generationDay,
            send_day: $sendDay,
            billing_period: BillingPeriod::PreviousMonth,
            due_in_days: 15,
            delivery_mode: DeliveryMode::ReviewBeforeSending,
            reminder_days_before: $mode === PricingMode::Fixed ? 3 : null,
            lines: $lines,
            adjustments: $adjustments,
        ), $this->owner);
    }
}
