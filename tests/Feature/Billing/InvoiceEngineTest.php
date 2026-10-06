<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * Generating RocketFlood's September 2026 invoices on October 3rd, 2026.
 */
final class InvoiceEngineTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
    }

    private function generate(BillingPlan $plan): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->post(route('workspace.clients.plans.invoices.store', [$this->workspace, $this->rocketFlood, $plan]));
    }

    public function test_the_dev_and_office_invoice_is_a_complete_snapshot_totalling_5059_20(): void
    {
        $plan = $this->devAndOffice();

        $response = $this->generate($plan);
        $invoice = Invoice::query()->sole();

        $response->assertRedirect(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertSessionHas('success', 'The September invoice for "Dev & Office" is ready.');

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertNull($invoice->number, 'drafts have no business number');
        $this->assertSame('Dev & Office', $invoice->title);
        $this->assertSame(['2026-09-01', '2026-09-30', '2026-10-01', '2026-10-01'], [
            $invoice->period_start->toDateString(),
            $invoice->period_end->toDateString(),
            $invoice->generated_on->toDateString(),
            $invoice->planned_send_on->toDateString(),
        ]);
        $this->assertSame([496000, 9920, 505920], [$invoice->subtotal_minor, $invoice->adjustments_minor, $invoice->total_minor]);

        $this->assertSame([
            [0, $this->team['Ali Hassan']->id, 'Ali Hassan', 'Developer', 100, 200000, 200000],
            [1, $this->team['Aamir Sattar']->id, 'Aamir Sattar', 'UI/UX Designer', 100, 120000, 120000],
            [2, $this->team['Azhar Hussain']->id, 'Azhar Hussain', null, 100, 30000, 30000],
            [3, null, 'Office Rent', null, 100, 110000, 110000],
            [4, null, 'Office Boy', null, 100, 18000, 18000],
            [5, null, 'Guard', null, 100, 18000, 18000],
        ], $invoice->lines->map(fn ($line) => [$line->position, $line->person_id, $line->description, $line->role_label, $line->quantity_centi, $line->unit_price_minor, $line->amount_minor])->all());

        $deel = $invoice->adjustments->sole();
        $this->assertSame(['Deel fee', 200, 9920], [$deel->label, $deel->value, $deel->amount_minor]);

        $this->assertSame([
            'name' => 'RocketFlood',
            'billing_email' => 'billing@rocketflood.com',
            'cc_emails' => [],
            'address' => "1200 Market Street\nSan Francisco, CA",
            'tax_id' => '12-3456789',
        ], $invoice->bill_to);

        $entry = AuditLog::query()->where('action', AuditAction::INVOICE_GENERATED->value)->sole();
        $this->assertSame(505920, $entry->metadata['total_minor']);
        $this->assertSame('2026-09-01', $entry->metadata['period_start']);
    }

    public function test_the_csr_invoice_is_prepared_on_the_1st_without_hours_and_planned_to_send_on_the_3rd(): void
    {
        $this->generate($this->csrTeam())->assertRedirect();

        $invoice = Invoice::query()->sole();

        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status);
        $this->assertSame('2026-10-01', $invoice->generated_on->toDateString());
        $this->assertSame('2026-10-03', $invoice->planned_send_on->toDateString());
        $this->assertSame([555, 455, 496, 496, 555], $invoice->lines->pluck('unit_price_minor')->all());
        $this->assertSame([null, null, null, null, null], $invoice->lines->pluck('quantity_centi')->all());
        $this->assertSame([null, null, null, null, null], $invoice->lines->pluck('amount_minor')->all());
        $this->assertSame(0, $invoice->total_minor);
    }

    public function test_generating_the_same_month_twice_returns_the_same_invoice(): void
    {
        $plan = $this->devAndOffice();

        $this->generate($plan)->assertRedirect();
        $first = Invoice::query()->sole();

        $this->generate($plan)
            ->assertRedirect(route('workspace.invoices.show', [$this->workspace, $first]))
            ->assertSessionHas('success', 'The September invoice for "Dev & Office" already exists. Here it is.');

        $cycle = app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03'));
        $again = app(GenerateInvoiceFromPlanAction::class)->handle($plan, $cycle, null);

        $this->assertTrue($again->is($first));
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(6, $first->lines()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_GENERATED->value)->count());
    }

    public function test_the_database_refuses_a_second_invoice_for_the_same_plan_and_month(): void
    {
        $plan = $this->devAndOffice();
        $this->generate($plan);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::factory()->for($this->rocketFlood)->create([
            'workspace_id' => $this->workspace->id,
            'billing_plan_id' => $plan->id,
            'period_start' => '2026-09-01',
        ]);
    }

    public function test_a_new_month_is_a_new_invoice(): void
    {
        $plan = $this->devAndOffice();
        $generate = app(GenerateInvoiceFromPlanAction::class);
        $schedule = app(BillingSchedule::class);

        $september = $generate->handle($plan, $schedule->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03')), null);
        $october = $generate->handle($plan, $schedule->latestDueCycle($plan, CarbonImmutable::parse('2026-11-01')), null);

        $this->assertFalse($september->is($october));
        $this->assertSame('2026-10-01', $october->period_start->toDateString());
        $this->assertSame('2026-11-01', $october->planned_send_on->toDateString());
    }

    public function test_the_month_follows_the_workspace_timezone(): void
    {
        $plan = $this->csrTeam();
        Carbon::setTestNow('2026-10-31 20:00:00');

        $this->workspace->forceFill(['timezone' => 'UTC'])->save();
        $this->generate($plan);
        $this->assertSame('2026-09-01', Invoice::query()->latest('id')->first()->period_start->toDateString());

        // 01:00 on November 1st in Karachi: October is over there, so October is billed.
        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $this->generate($plan);
        $october = Invoice::query()->latest('id')->first();

        $this->assertSame('2026-10-01', $october->period_start->toDateString());
        $this->assertSame(['2026-11-01', '2026-11-03'], [$october->generated_on->toDateString(), $october->planned_send_on->toDateString()]);
    }

    public function test_paused_plans_and_archived_clients_do_not_generate(): void
    {
        $plan = $this->devAndOffice();
        $plan->forceFill(['paused_at' => now()])->save();

        $this->generate($plan)->assertSessionHas('error');
        $this->assertSame(0, Invoice::query()->count());

        $plan->forceFill(['paused_at' => null])->save();
        $this->rocketFlood->forceFill(['archived_at' => now()])->save();

        $this->generate($plan)->assertSessionHas('error');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_changes_after_generation_never_reach_the_invoice(): void
    {
        $plan = $this->devAndOffice();
        $this->generate($plan);
        $invoice = Invoice::query()->sole();
        $before = $this->snapshot($invoice);

        $plan->lines()->where('description', 'Guard')->update(['unit_price_minor' => 99999]);
        $plan->lines()->where('description', 'Office Boy')->delete();
        $plan->adjustments()->update(['value' => 500]);
        $plan->forceFill(['name' => 'Renamed plan', 'due_in_days' => 60])->save();

        $this->team['Ali Hassan']->forceFill(['name' => 'Ali H.', 'title' => 'CTO'])->save();
        $this->rocketFlood->forceFill(['name' => 'RocketFlood Inc', 'billing_email' => 'new@rocketflood.com', 'address' => null])->save();

        $this->assertSame($before, $this->snapshot($invoice->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Invoice $invoice): array
    {
        $invoice->load(['lines', 'adjustments']);

        return [
            'invoice' => $invoice->only(['title', 'bill_to', 'subtotal_minor', 'adjustments_minor', 'total_minor', 'due_in_days']),
            'lines' => $invoice->lines->map->only(['description', 'role_label', 'quantity_centi', 'unit_price_minor', 'amount_minor'])->all(),
            'adjustments' => $invoice->adjustments->map->only(['label', 'value', 'amount_minor'])->all(),
        ];
    }
}
