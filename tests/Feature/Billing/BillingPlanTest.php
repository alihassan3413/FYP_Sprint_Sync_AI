<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * RocketFlood's two recurring invoices, built with the real numbers:
 * Dev & Office totals $5,059.20 a month; CSR Team bills five hourly rates.
 */
final class BillingPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Client $rocketFlood;

    /** @var array<string, Person> */
    private array $team = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 10:00:00');

        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->rocketFlood = Client::factory()->rocketFlood()->for($this->workspace)->create();

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

    /**
     * @return array<string, mixed>
     */
    private function devAndOffice(array $overrides = []): array
    {
        $member = fn (string $name, string $amount) => [
            'person' => $this->team[$name]->public_id,
            'description' => $name,
            'role_label' => $this->team[$name]->title,
            'unit_price' => $amount,
        ];
        $item = fn (string $name, string $amount) => ['person' => null, 'description' => $name, 'unit_price' => $amount];

        return [
            'name' => 'Dev & Office',
            'currency' => 'USD',
            'pricing_mode' => 'fixed',
            'send_day' => 1,
            'billing_period' => 'previous_month',
            'due_in_days' => 15,
            'delivery_mode' => 'review_before_sending',
            'reminder_days_before' => 3,
            'lines' => [
                $member('Ali Hassan', '2000'),
                $member('Aamir Sattar', '1200.00'),
                $member('Azhar Hussain', '300'),
                $item('Office Rent', '1100'),
                $item('Office Boy', '180'),
                $item('Guard', '180'),
            ],
            'adjustments' => [
                ['kind' => 'fee', 'type' => 'percentage', 'label' => 'Deel fee', 'value' => '2'],
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function csrTeam(array $overrides = []): array
    {
        $rate = fn (string $name, string $rate) => [
            'person' => $this->team[$name]->public_id,
            'description' => $name,
            'role_label' => 'CSR',
            'unit_price' => $rate,
        ];

        return [
            'name' => 'CSR Team',
            'currency' => 'USD',
            'pricing_mode' => 'hourly',
            'send_day' => 3,
            'billing_period' => 'current_month',
            'due_in_days' => 15,
            'delivery_mode' => 'review_before_sending',
            'reminder_days_before' => 7,
            'lines' => [
                $rate('Muhammad Usman Ghani', '5.55'),
                $rate('Abdul Wahab', '4.55'),
                $rate('Faheem', '4.96'),
                $rate('Abdul Rehman', '4.96'),
                $rate('Muhammad Faizan', '5.55'),
            ],
            'adjustments' => [],
            ...$overrides,
        ];
    }

    private function store(array $payload, ?Client $client = null): TestResponse
    {
        return $this->actingAs($this->owner)
            ->from(route('workspace.clients.show', [$this->workspace, $client ?? $this->rocketFlood]))
            ->post(route('workspace.clients.plans.store', [$this->workspace, $client ?? $this->rocketFlood]), $payload);
    }

    private function update(BillingPlan $plan, array $payload): TestResponse
    {
        return $this->actingAs($this->owner)
            ->from(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->put(route('workspace.clients.plans.update', [$this->workspace, $this->rocketFlood, $plan]), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function planProps(): array
    {
        $plans = [];

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$plans) {
                $plans = $page->toArray()['props']['plans'];
            });

        return collect($plans)->keyBy('name')->all();
    }

    public function test_the_owner_sets_up_dev_and_office_totalling_5059_20_a_month(): void
    {
        $this->store($this->devAndOffice())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertSessionHas('success', 'Recurring invoice "Dev & Office" saved.');

        $plan = BillingPlan::query()->sole();

        $this->assertSame($this->workspace->id, $plan->workspace_id);
        $this->assertSame($this->rocketFlood->id, $plan->client_id);
        $this->assertSame(PricingMode::Fixed, $plan->pricing_mode);
        $this->assertSame(Currency::USD, $plan->currency);
        $this->assertSame(3, $plan->reminder_days_before);

        $this->assertSame(
            [
                [0, $this->team['Ali Hassan']->id, 'Ali Hassan', 'Developer', 200000],
                [1, $this->team['Aamir Sattar']->id, 'Aamir Sattar', 'UI/UX Designer', 120000],
                [2, $this->team['Azhar Hussain']->id, 'Azhar Hussain', null, 30000],
                [3, null, 'Office Rent', null, 110000],
                [4, null, 'Office Boy', null, 18000],
                [5, null, 'Guard', null, 18000],
            ],
            $plan->lines->map(fn ($line) => [$line->position, $line->person_id, $line->description, $line->role_label, $line->unit_price_minor])->all(),
        );

        $deel = $plan->adjustments->sole();
        $this->assertSame([AdjustmentKind::Fee, AdjustmentType::Percentage, 'Deel fee', 200], [$deel->kind, $deel->type, $deel->label, $deel->value]);

        $props = $this->planProps()['Dev & Office'];
        $this->assertSame(496000, $props['subtotal_minor']);
        $this->assertSame([9920], $props['adjustment_amounts_minor']);
        $this->assertSame(505920, $props['total_minor']);
        $this->assertSame(1, $plan->generation_day);
        $this->assertSame(1, $plan->send_day);
        $this->assertSame('2026-11-01', $props['next_generation_on']);
        $this->assertSame('2026-11-01', $props['next_send_on']);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$props['next_period_start'], $props['next_period_end']]);

        $entry = AuditLog::query()->where('action', AuditAction::BILLING_PLAN_CREATED->value)->sole();
        $this->assertSame(505920, $entry->metadata['monthly_total_minor']);
        $this->assertSame('fixed', $entry->metadata['pricing_mode']);
        $this->assertSame('review_before_sending', $entry->metadata['delivery_mode']);
        $this->assertArrayNotHasKey('lines.0.unit_price', $entry->metadata);
    }

    public function test_the_csr_team_plan_stores_rates_only_and_bills_last_month(): void
    {
        $this->store($this->csrTeam())->assertSessionHasNoErrors();

        $plan = BillingPlan::query()->sole();

        $this->assertSame(PricingMode::Hourly, $plan->pricing_mode);
        $this->assertSame(BillingPeriod::PreviousMonth, $plan->billing_period, 'hourly plans always bill the month that ended');
        $this->assertNull($plan->reminder_days_before, 'hourly plans are reviewed when the hours are in, not N days before');
        $this->assertSame(1, $plan->generation_day, 'hourly drafts are generated on the 1st, when last month is complete');
        $this->assertSame(3, $plan->send_day);
        $this->assertSame([555, 455, 496, 496, 555], $plan->lines->pluck('unit_price_minor')->all());
        $this->assertNotContains(null, $plan->lines->pluck('person_id')->all());

        $props = $this->planProps()['CSR Team'];
        $this->assertNull($props['total_minor']);
        $this->assertSame('2026-11-01', $props['next_generation_on']);
        $this->assertSame('2026-11-03', $props['next_send_on']);
        $this->assertSame('2026-10-01', $props['next_period_start']);
    }

    public function test_the_generation_day_is_derived_and_cannot_be_posted(): void
    {
        $this->store($this->csrTeam(['send_day' => 10, 'generation_day' => 9]))->assertSessionHasNoErrors();
        $this->store($this->devAndOffice(['send_day' => 15, 'generation_day' => 2]))->assertSessionHasNoErrors();

        $plans = BillingPlan::query()->get()->keyBy('name');
        $this->assertSame([1, 10], [$plans['CSR Team']->generation_day, $plans['CSR Team']->send_day]);
        $this->assertSame([15, 15], [$plans['Dev & Office']->generation_day, $plans['Dev & Office']->send_day]);
    }

    public function test_upcoming_dates_use_the_workspace_timezone(): void
    {
        $this->store($this->csrTeam())->assertSessionHasNoErrors();

        // 20:00 UTC on Oct 31 is already 01:00 on Nov 1 in Karachi.
        Carbon::setTestNow('2026-10-31 20:00:00');

        $this->workspace->forceFill(['timezone' => 'UTC'])->save();
        $this->assertSame(['2026-11-01', '2026-11-03', '2026-10-01'], $this->nextDates());

        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $this->assertSame(['2026-12-01', '2026-12-03', '2026-11-01'], $this->nextDates(), 'Nov 1 has started in Karachi, so the next draft is December');
    }

    /**
     * @return list<string>
     */
    private function nextDates(): array
    {
        $csr = $this->planProps()['CSR Team'];

        return [$csr['next_generation_on'], $csr['next_send_on'], $csr['next_period_start']];
    }

    public function test_hourly_plans_only_bill_team_members_at_a_positive_rate(): void
    {
        $payload = $this->csrTeam();
        $payload['lines'][] = ['person' => null, 'description' => 'Night shift', 'unit_price' => '6'];
        $payload['lines'][0]['unit_price'] = '0';

        $this->store($payload)->assertSessionHasErrors(['lines.5.person', 'lines.0.unit_price']);

        $this->assertSame(0, BillingPlan::query()->count());
    }

    public function test_fixed_fees_and_discounts_are_calculated_from_the_subtotal(): void
    {
        $this->store($this->devAndOffice([
            'name' => 'Retainer',
            'lines' => [['description' => 'Monthly retainer', 'unit_price' => '1000']],
            'adjustments' => [
                ['kind' => 'fee', 'type' => 'fixed', 'label' => 'Bank fee', 'value' => '50'],
                ['kind' => 'discount', 'type' => 'percentage', 'label' => 'Loyalty', 'value' => '10'],
                ['kind' => 'tax', 'type' => 'percentage', 'label' => 'Sales tax', 'value' => '2.5'],
            ],
        ]))->assertSessionHasNoErrors();

        $props = $this->planProps()['Retainer'];

        $this->assertSame(100000, $props['subtotal_minor']);
        $this->assertSame([5000, -10000, 2500], $props['adjustment_amounts_minor']);
        $this->assertSame(97500, $props['total_minor']);
        $this->assertSame([0, 1, 2], BillingPlan::query()->sole()->adjustments->pluck('position')->all());
        $this->assertSame(250, BillingPlan::query()->sole()->adjustments[2]->value);
    }

    public function test_odd_cents_round_half_up_once(): void
    {
        $this->store($this->devAndOffice([
            'name' => 'Rounding',
            'lines' => [['description' => 'Odd amount', 'unit_price' => '0.25']],
            'adjustments' => [['kind' => 'fee', 'type' => 'percentage', 'label' => 'Fee', 'value' => '2']],
        ]))->assertSessionHasNoErrors();

        $props = $this->planProps()['Rounding'];

        $this->assertSame([1], $props['adjustment_amounts_minor'], '2% of 25 cents is 0.5 cents, rounded up');
        $this->assertSame(26, $props['total_minor']);
    }

    public function test_a_discount_larger_than_the_subtotal_is_rejected(): void
    {
        $this->store($this->devAndOffice([
            'adjustments' => [['kind' => 'discount', 'type' => 'fixed', 'label' => 'Too much', 'value' => '5000']],
        ]))->assertSessionHasErrors('adjustments');

        $this->assertSame(0, BillingPlan::query()->count());
    }

    public function test_amounts_and_percentages_are_validated_without_floats(): void
    {
        $payload = $this->devAndOffice();
        $payload['lines'][0]['unit_price'] = '2000.555';
        $payload['lines'][1]['unit_price'] = '-5';
        $payload['lines'][2]['unit_price'] = 300.5;
        $payload['adjustments'][0]['value'] = '101';

        $this->store($payload)->assertSessionHasErrors([
            'lines.0.unit_price',
            'lines.1.unit_price',
            'lines.2.unit_price',
            'adjustments.0.value',
        ]);

        $this->assertSame(0, BillingPlan::query()->count());
    }

    public function test_schedule_values_are_validated(): void
    {
        $this->store($this->devAndOffice(['send_day' => 31, 'due_in_days' => 400, 'reminder_days_before' => 2, 'lines' => []]))
            ->assertSessionHasErrors(['send_day', 'due_in_days', 'reminder_days_before', 'lines']);
    }

    public function test_review_plans_need_a_reminder_and_auto_send_plans_drop_it(): void
    {
        $this->store($this->devAndOffice(['reminder_days_before' => null]))->assertSessionHasErrors('reminder_days_before');

        $this->store($this->devAndOffice(['delivery_mode' => 'auto_send', 'reminder_days_before' => 7]))->assertSessionHasNoErrors();

        $plan = BillingPlan::query()->sole();
        $this->assertSame(DeliveryMode::AutoSend, $plan->delivery_mode);
        $this->assertNull($plan->reminder_days_before);
    }

    public function test_team_members_must_belong_to_this_workspace_and_appear_once(): void
    {
        $stranger = Person::factory()->for(Workspace::factory()->create())->create();

        $payload = $this->devAndOffice();
        $payload['lines'][0]['person'] = $stranger->public_id;
        $payload['lines'][2]['person'] = $this->team['Aamir Sattar']->public_id;

        $this->store($payload)->assertSessionHasErrors(['lines.0.person', 'lines.2.person']);

        $this->assertSame(0, BillingPlan::query()->count());
    }

    public function test_names_are_unique_per_client_ignoring_case(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();

        $this->store($this->devAndOffice(['name' => 'dev & OFFICE']))->assertSessionHasErrors('name');

        $other = Client::factory()->for($this->workspace)->create();
        $this->store($this->devAndOffice(), $other)->assertSessionHasNoErrors();

        $this->assertSame(2, BillingPlan::query()->count());
    }

    public function test_a_double_submitted_create_makes_one_plan(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();
        $this->store($this->devAndOffice())->assertSessionHasErrors('name');

        $this->assertSame(1, BillingPlan::query()->count());
        $this->assertSame(6, BillingPlan::query()->sole()->lines()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BILLING_PLAN_CREATED->value)->count());
    }

    public function test_editing_replaces_lines_and_adjustments_in_order(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();
        $plan = BillingPlan::query()->sole();

        $payload = $this->devAndOffice(['name' => 'Dev, Design & Office', 'due_in_days' => 30]);
        $payload['lines'] = array_reverse(array_slice($payload['lines'], 0, 4));
        $payload['adjustments'][] = ['kind' => 'discount', 'type' => 'fixed', 'label' => 'Goodwill', 'value' => '100'];

        $this->update($plan, $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Recurring invoice "Dev, Design & Office" updated. Future invoices will use the changes.');

        $plan->refresh();
        $this->assertSame('Dev, Design & Office', $plan->name);
        $this->assertSame(30, $plan->due_in_days);
        $this->assertSame(['Office Rent', 'Azhar Hussain', 'Aamir Sattar', 'Ali Hassan'], $plan->lines->pluck('description')->all());
        $this->assertSame([0, 1, 2, 3], $plan->lines->pluck('position')->all());
        $this->assertSame(2, $plan->adjustments()->count());

        $props = $this->planProps()['Dev, Design & Office'];
        $this->assertSame(460000, $props['subtotal_minor']);
        $this->assertSame(460000 + 9200 - 10000, $props['total_minor']);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BILLING_PLAN_UPDATED->value)->count());
    }

    public function test_saving_the_same_plan_again_changes_nothing(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();
        $plan = BillingPlan::query()->sole();

        $this->update($plan, $this->devAndOffice())->assertSessionHasNoErrors();
        $this->update($plan, $this->devAndOffice())->assertSessionHasNoErrors();

        $this->assertSame(6, $plan->lines()->count());
        $this->assertSame(1, $plan->adjustments()->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::BILLING_PLAN_UPDATED->value)->count());
    }

    public function test_a_failed_save_leaves_nothing_half_written(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();
        $plan = BillingPlan::query()->sole();

        $broken = new StoreBillingPlanData(
            name: 'Broken',
            currency: Currency::USD,
            pricing_mode: PricingMode::Fixed,
            generation_day: 1,
            send_day: 1,
            billing_period: BillingPeriod::PreviousMonth,
            due_in_days: 15,
            delivery_mode: DeliveryMode::ReviewBeforeSending,
            reminder_days_before: 3,
            lines: [
                ['person_id' => null, 'description' => 'Fine', 'role_label' => null, 'unit_price_minor' => 100],
                ['person_id' => 999_999, 'description' => 'Missing person', 'role_label' => null, 'unit_price_minor' => 100],
            ],
            adjustments: [],
        );

        try {
            app(SaveBillingPlanAction::class)->handle($this->rocketFlood, $plan, $broken, $this->owner);
            $this->fail('Expected the foreign key to reject the line.');
        } catch (QueryException) {
        }

        $plan->refresh();
        $this->assertSame('Dev & Office', $plan->name);
        $this->assertSame(6, $plan->lines()->count());
        $this->assertSame(1, $plan->adjustments()->count());

        try {
            app(SaveBillingPlanAction::class)->handle($this->rocketFlood, null, $broken, $this->owner);
        } catch (QueryException) {
        }

        $this->assertSame(1, BillingPlan::query()->count());
    }

    public function test_pausing_and_resuming_are_idempotent_and_audited_once(): void
    {
        $plan = BillingPlan::factory()->for($this->rocketFlood)->create(['workspace_id' => $this->workspace->id]);
        $pause = route('workspace.clients.plans.pause', [$this->workspace, $this->rocketFlood, $plan]);
        $resume = route('workspace.clients.plans.resume', [$this->workspace, $this->rocketFlood, $plan]);

        $this->actingAs($this->owner)->post($pause)->assertRedirect();
        $this->actingAs($this->owner)->post($pause)->assertRedirect();
        $this->assertTrue($plan->fresh()->isPaused());

        $this->actingAs($this->owner)->post($resume)->assertRedirect();
        $this->actingAs($this->owner)->post($resume)->assertRedirect();
        $this->assertFalse($plan->fresh()->isPaused());

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BILLING_PLAN_PAUSED->value)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BILLING_PLAN_RESUMED->value)->count());
    }

    public function test_archived_clients_cannot_get_new_recurring_invoices(): void
    {
        $this->rocketFlood->forceFill(['archived_at' => now()])->save();

        $this->store($this->devAndOffice())->assertSessionHasErrors('name');

        $this->assertSame(0, BillingPlan::query()->count());
    }

    public function test_the_client_page_sends_public_ids_only(): void
    {
        $this->store($this->devAndOffice())->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('plans', 1)
                ->missing('plans.0.id')
                ->missing('plans.0.client_id')
                ->missing('plans.0.lines.0.id')
                ->missing('plans.0.lines.0.person_id')
                ->where('plans.0.lines.0.person_public_id', $this->team['Ali Hassan']->public_id)
                ->where('plans.0.lines.3.person_public_id', null)
                ->where('today', '2026-10-03')
                ->where('teamMembers', fn ($members) => collect($members)->every(fn ($member) => array_keys($member) === ['public_id', 'name', 'title']))
                ->has('teamMembers', 8));

        $plan = BillingPlan::query()->sole();
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $plan->public_id);
        $this->assertStringEndsWith("/recurring-invoices/{$plan->public_id}", route('workspace.clients.plans.update', [$this->workspace, $this->rocketFlood, $plan]));
    }

    public function test_numeric_ids_and_other_clients_plans_are_not_found(): void
    {
        $plan = BillingPlan::factory()->for($this->rocketFlood)->create(['workspace_id' => $this->workspace->id]);
        $otherClient = Client::factory()->for($this->workspace)->create();

        $this->actingAs($this->owner)
            ->put("/{$this->workspace->slug}/clients/{$this->rocketFlood->public_id}/recurring-invoices/{$plan->id}", $this->devAndOffice())
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->put(route('workspace.clients.plans.update', [$this->workspace, $otherClient, $plan]), $this->devAndOffice())
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('workspace.clients.plans.pause', [$this->workspace, $otherClient, $plan]))
            ->assertNotFound();

        $this->assertFalse($plan->fresh()->isPaused());
    }
}
