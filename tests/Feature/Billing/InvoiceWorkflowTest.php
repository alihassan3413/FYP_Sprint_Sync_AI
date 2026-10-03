<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * The CSR hours workflow and the approval lifecycle: Needs hours → Ready to
 * review → Approved (scheduled, cancellable, no number) → Issued
 * (INV-2026-0001, frozen forever).
 */
final class InvoiceWorkflowTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
    }

    private function invoiceFor(BillingPlan $plan, string $today = '2026-10-03'): Invoice
    {
        return app(GenerateInvoiceFromPlanAction::class)->handle(
            $plan,
            app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse($today)),
            $this->owner,
        );
    }

    /**
     * @param  array<int|string, mixed>  $lines
     */
    private function saveLines(Invoice $invoice, array $lines, ?User $as = null): TestResponse
    {
        $response = $this->actingAs($as ?? $this->owner)
            ->withHeader('X-Inertia', 'true')
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->put(route('workspace.invoices.lines.update', [$this->workspace, $invoice]), ['lines' => $lines]);

        $this->flushHeaders();

        return $response;
    }

    private function approve(Invoice $invoice, ?int $version = null): TestResponse
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route('workspace.invoices.approve', [$this->workspace, $invoice]), ['version' => $version ?? $invoice->fresh()->version]);

        $this->flushHeaders();

        return $response;
    }

    public function test_entering_csr_hours_calculates_on_the_server(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->saveLines($invoice, ['0' => '160', '1' => '150.5', '2' => '0', '3' => '', '4' => '120.25'])->assertSessionHasNoErrors();

        $invoice->refresh()->load('lines');
        $this->assertSame([16000, 15050, 0, null, 12025], $invoice->lines->pluck('quantity_centi')->all());
        $this->assertSame([88800, 68478, 0, null, 66739], $invoice->lines->pluck('amount_minor')->all(), '160 × 5.55 = 888.00; 150.5 × 4.55 = 684.775 → 684.78');
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status, 'a blank line means hours are still missing');
        $this->assertSame(88800 + 68478 + 66739, $invoice->total_minor);
    }

    public function test_the_last_hours_make_it_ready_and_clearing_one_sends_it_back(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->saveLines($invoice, ['0' => '160', '1' => '160', '2' => '160', '3' => '160'])->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->fresh()->status);

        $this->saveLines($invoice, ['4' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->fresh()->status, '0 hours is an answer: nothing to bill');
        $this->assertSame(88800 + 72800 + 79360 + 79360, $invoice->fresh()->total_minor);

        $this->saveLines($invoice, ['2' => ''])->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->fresh()->status);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_READY->value)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_UPDATED->value)->count(), 'autosaves within 10 minutes are one audit entry');
    }

    public function test_saving_the_same_hours_again_changes_nothing(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->saveLines($invoice, ['0' => '160']);
        $version = $invoice->fresh()->version;

        $this->saveLines($invoice, ['0' => '160.00']);
        $this->saveLines($invoice, ['0' => '160']);

        $this->assertSame($version, $invoice->fresh()->version);
        $this->assertSame(5, $invoice->lines()->count());
    }

    public function test_hours_are_validated(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->saveLines($invoice, ['0' => '160.555', '1' => '-1', '2' => '800', '3' => 'abc'])
            ->assertSessionHasErrors(['lines.0', 'lines.1', 'lines.2', 'lines.3']);

        $this->saveLines($invoice, ['9' => '10'])->assertSessionHasErrors('lines');

        $this->assertSame([null, null, null, null, null], $invoice->lines()->pluck('quantity_centi')->all());
    }

    public function test_rates_cannot_be_changed_through_the_hours_endpoint(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->saveLines($invoice, ['0' => ['unit_price_minor' => 1, 'quantity_centi' => 1]])->assertSessionHasErrors('lines.0');

        $this->actingAs($this->owner)
            ->put(route('workspace.invoices.lines.update', [$this->workspace, $invoice]), [
                'lines' => ['0' => '10'],
                'unit_price_minor' => 1,
                'total_minor' => 1,
                'status' => 'approved',
            ])->assertSessionHasNoErrors();

        $invoice->refresh()->load('lines');
        $this->assertSame([555, 455, 496, 496, 555], $invoice->lines->pluck('unit_price_minor')->all());
        $this->assertSame(5550, $invoice->total_minor);
        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->status);
    }

    public function test_a_fixed_draft_can_be_corrected_for_this_invoice_only(): void
    {
        $plan = $this->devAndOffice();
        $invoice = $this->invoiceFor($plan);

        $this->saveLines($invoice, ['5' => '200'])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(498000, $invoice->subtotal_minor);
        $this->assertSame(498000 + 9960, $invoice->total_minor);
        $this->assertSame(18000, $plan->lines()->where('description', 'Guard')->value('unit_price_minor'), 'the recurring invoice keeps its own amount');
    }

    private function cancelSending(Invoice $invoice): TestResponse
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route('workspace.invoices.cancel-sending', [$this->workspace, $invoice]));

        $this->flushHeaders();

        return $response;
    }

    private function issue(Invoice $invoice): Invoice
    {
        return app(IssueInvoiceAction::class)->handle($invoice, null, sendNow: true);
    }

    private function auditCount(AuditAction $action): int
    {
        return AuditLog::query()->where('action', $action->value)->count();
    }

    public function test_approving_schedules_the_invoice_and_assigns_no_number(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());

        $this->approve($invoice)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Approved. Scheduled to send in 1 hour. You can cancel sending if you need to make a change.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);
        $this->assertNull($invoice->number, 'approving never assigns the business number');
        $this->assertSame('2026-10-03 11:00:00', $invoice->send_after->toDateTimeString(), '1 hour after approval');
        $this->assertSame('2026-10-03 10:00:00', $invoice->approved_at->toDateTimeString());
        $this->assertSame($this->owner->id, $invoice->approved_by);
        $this->assertNull($invoice->issue_date);
        $this->assertNull($invoice->issued_at);
        $this->assertSame(505920, $invoice->total_minor);
        $this->assertNull(DB::table('invoice_number_sequences')->value('last_number'), 'no number is taken');

        $entry = AuditLog::query()->where('action', AuditAction::INVOICE_APPROVED->value)->sole();
        $this->assertSame('2026-10-03T11:00:00+00:00', $entry->metadata['send_after']);
    }

    public function test_the_cancel_window_comes_from_config(): void
    {
        config(['finance.approval_send_delay_minutes' => 30]);
        $invoice = $this->invoiceFor($this->devAndOffice());

        $this->approve($invoice)->assertSessionHas('success', 'Approved. Scheduled to send in 30 minutes. You can cancel sending if you need to make a change.');

        $this->assertSame('2026-10-03 10:30:00', $invoice->fresh()->send_after->toDateTimeString());
    }

    public function test_double_approve_approves_once(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $version = $invoice->version;

        $this->approve($invoice, $version)->assertSessionHasNoErrors();
        $first = $invoice->fresh();

        $this->travel(5)->minutes();
        $this->approve($invoice, $version)->assertSessionHasNoErrors();
        app(ApproveInvoiceAction::class)->handle(Invoice::query()->findOrFail($invoice->id), $version, $this->owner);

        $again = $invoice->fresh();
        $this->assertSame($first->send_after->toDateTimeString(), $again->send_after->toDateTimeString(), 'one send_after, not pushed back');
        $this->assertSame($first->version, $again->version);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_APPROVED));
    }

    public function test_an_approved_invoice_is_locked_until_sending_is_cancelled(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());
        $this->saveLines($invoice, ['0' => '160', '1' => '160', '2' => '160', '3' => '160', '4' => '160']);
        $this->approve($invoice);

        $this->saveLines($invoice, ['0' => '1'])->assertSessionHas('error');
        $this->assertSame(16000, $invoice->lines()->where('position', 0)->value('quantity_centi'));

        try {
            $invoice->fresh()->lines()->first()->forceFill(['quantity_centi' => 1])->save();
            $this->fail('Lines of an approved invoice must not change.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $invoice->fresh()->forceFill(['total_minor' => 1])->save();
    }

    public function test_cancel_sending_reopens_the_invoice_for_editing(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());
        $this->saveLines($invoice, ['0' => '160', '1' => '160', '2' => '160', '3' => '160', '4' => '160']);
        $this->approve($invoice);

        $this->cancelSending($invoice)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Sending cancelled. You can edit the invoice again.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertNull($invoice->approved_at);
        $this->assertNull($invoice->approved_by);
        $this->assertNull($invoice->send_after);
        $this->assertNull($invoice->number);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_SEND_CANCELLED));

        $this->saveLines($invoice, ['1' => '150'])->assertSessionHasNoErrors();
        $this->assertSame(15000, $invoice->lines()->where('position', 1)->value('quantity_centi'));
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $this->approve($invoice);

        $this->cancelSending($invoice)->assertSessionHasNoErrors();
        $version = $invoice->fresh()->version;
        $this->cancelSending($invoice)->assertSessionHasNoErrors();

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->fresh()->status);
        $this->assertSame($version, $invoice->fresh()->version);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_SEND_CANCELLED));
    }

    public function test_approving_again_after_a_fix_still_uses_no_number(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $this->approve($invoice);
        $this->cancelSending($invoice);
        $this->saveLines($invoice, ['5' => '200']);

        $this->travel(10)->minutes();
        $this->approve($invoice)->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(498000 + 9960, $invoice->total_minor);
        $this->assertSame('2026-10-03 11:10:00', $invoice->send_after->toDateTimeString(), 'a fresh window from the new approval');
        $this->assertNull(DB::table('invoice_number_sequences')->value('last_number'));
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_APPROVED));
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_REAPPROVED));
    }

    public function test_issuing_assigns_exactly_one_number(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $this->approve($invoice);
        $staleCopy = Invoice::query()->findOrFail($invoice->id);

        $issued = $this->issue($invoice);
        $this->issue($invoice->fresh());
        $this->issue($staleCopy);

        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertSame('INV-2026-0001', $issued->number);
        $this->assertSame('2026-10-03', $issued->issue_date->toDateString());
        $this->assertSame('2026-10-18', $issued->due_date->toDateString());
        $this->assertNotNull($issued->issued_at);
        $this->assertSame(1, DB::table('invoice_number_sequences')->value('last_number'));
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_ISSUED));
    }

    public function test_only_approved_invoices_can_be_issued(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());

        try {
            $this->issue($invoice);
            $this->fail('A ready-to-review invoice must not be issued.');
        } catch (InvoiceException) {
        }

        $this->assertNull($invoice->fresh()->number);
        $this->assertNull(DB::table('invoice_number_sequences')->value('last_number'));
    }

    public function test_numbers_are_sequential_per_workspace_and_follow_issue_order(): void
    {
        $dev = $this->invoiceFor($this->devAndOffice());
        $csr = $this->invoiceFor($this->csrTeam());
        $this->saveLines($csr, ['0' => '1', '1' => '1', '2' => '1', '3' => '1', '4' => '1']);

        $this->approve($dev);
        $this->approve($csr);
        $this->issue($csr);
        $this->issue($dev);

        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->ownedBy($otherOwner)->create();
        $foreign = Invoice::factory()->for(Client::factory()->for($other))->create([
            'workspace_id' => $other->id,
            'bill_from' => ['business_name' => 'Other Co', 'billing_email' => 'a@b.co', 'address_line1' => '1 Road', 'city' => 'Karachi', 'country' => 'Pakistan'],
        ]);
        app(ApproveInvoiceAction::class)->handle($foreign, $foreign->version, $otherOwner);
        $this->issue($foreign);

        $this->assertSame(['INV-2026-0002', 'INV-2026-0001'], [$dev->fresh()->number, $csr->fresh()->number]);
        $this->assertSame('INV-2026-0001', $foreign->fresh()->number);
    }

    public function test_the_database_refuses_a_duplicate_number_in_a_workspace(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $this->approve($invoice);
        $this->issue($invoice);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::factory()->for($this->rocketFlood)->create(['workspace_id' => $this->workspace->id, 'number' => 'INV-2026-0001']);
    }

    public function test_an_issued_invoice_cannot_be_reopened_or_edited(): void
    {
        $plan = $this->devAndOffice();
        $invoice = $this->invoiceFor($plan);
        $this->approve($invoice);
        $this->issue($invoice);

        $this->cancelSending($invoice)->assertSessionHas('error');
        $this->saveLines($invoice, ['5' => '1'])->assertSessionHas('error');
        $this->approve($invoice)->assertSessionHasNoErrors()->assertSessionHas('success', 'This invoice was already issued as INV-2026-0001.');

        $plan->lines()->update(['unit_price_minor' => 1]);
        $this->team['Ali Hassan']->forceFill(['name' => 'Someone else'])->save();
        $this->rocketFlood->forceFill(['billing_email' => 'changed@rocketflood.com'])->save();

        $fresh = $invoice->fresh()->load('lines');
        $this->assertSame(InvoiceStatus::Issued, $fresh->status);
        $this->assertSame(505920, $fresh->total_minor);
        $this->assertSame('Ali Hassan', $fresh->lines[0]->description);
        $this->assertSame('billing@rocketflood.com', $fresh->bill_to['billing_email']);

        $this->expectException(LogicException::class);
        $fresh->forceFill(['status' => InvoiceStatus::ReadyToReview])->save();
    }

    public function test_an_invoice_still_needing_hours_cannot_be_approved(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->approve($invoice)->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::NeedsHours, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->send_after);
    }

    public function test_approving_an_out_of_date_review_is_refused(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        $seen = $invoice->version;

        $this->saveLines($invoice, ['5' => '200']);

        $this->approve($invoice, $seen)->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->fresh()->status);
    }

    public function test_approval_recalculates_instead_of_trusting_stored_totals(): void
    {
        $invoice = $this->invoiceFor($this->devAndOffice());
        DB::table('invoices')->whereKey($invoice->id)->update(['total_minor' => 1, 'subtotal_minor' => 1]);

        $this->approve($invoice)->assertSessionHasNoErrors();

        $this->assertSame(505920, $invoice->fresh()->total_minor);
    }

    public function test_the_invoice_page_shows_the_snapshot(): void
    {
        $invoice = $this->invoiceFor($this->csrTeam());

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('invoices/show')
                ->where('invoice.summary.public_id', $invoice->public_id)
                ->where('invoice.summary.status', 'needs_hours')
                ->where('invoice.summary.lines_missing_hours', 5)
                ->where('invoice.lines.0.description', 'Muhammad Usman Ghani')
                ->where('invoice.lines.0.unit_price_minor', 555)
                ->missing('invoice.id')
                ->missing('invoice.lines.0.id')
                ->missing('invoice.lines.0.person_id')
                ->where('canApprove', true));
    }

    public function test_the_invoices_home_lists_what_needs_the_owner(): void
    {
        $csr = $this->invoiceFor($this->csrTeam());
        $dev = $this->invoiceFor($this->devAndOffice());

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('invoices/index')
                ->has('needsYou', 2)
                ->has('invoices', 2)
                ->where('needsYou', fn ($rows) => collect($rows)->pluck('status')->sort()->values()->all() === ['needs_hours', 'ready_to_review']));

        $this->approve($dev);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->has('needsYou', 1)->where('needsYou.0.public_id', $csr->public_id));
    }
}
