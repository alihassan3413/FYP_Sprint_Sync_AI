<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\SendInvoiceDeliveryAction;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Data\InvoiceDisplayStatus;
use App\Modules\Billing\Data\InvoiceSource;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\PaymentTotals;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\Feature\Billing\Concerns\FakesWorkspaceMail;
use Tests\TestCase;

final class ManualInvoiceTest extends TestCase
{
    use BuildsRocketFlood, FakesWorkspaceMail, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->setUpRocketFlood();
        $this->fakeWorkspaceMail();
        $this->connectMail();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'recipient_type' => 'client',
            'client' => $this->rocketFlood->public_id,
            'title' => 'Website audit',
            'currency' => 'USD',
            'issue_date' => '2026-10-15',
            'due_date' => '2026-10-30',
            'lines' => [
                ['description' => 'Discovery workshop', 'quantity' => '2', 'rate' => '450.00'],
                ['description' => 'Audit report', 'quantity' => '1.5', 'rate' => '333.33'],
            ],
            'adjustments' => [
                ['kind' => 'discount', 'type' => 'percentage', 'value' => '10'],
                ['kind' => 'tax', 'type' => 'percentage', 'value' => '8.25', 'label' => 'Sales tax'],
            ],
            'notes' => 'Thank you for your business.',
            ...$overrides,
        ];
    }

    private function create(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoices.create', $this->workspace))
            ->post(route('workspace.invoices.store', $this->workspace), $this->payload($overrides));
    }

    private function attempt(string $method, string $url, array $data = []): TestResponse
    {
        $response = $this->actingAs($this->owner)->withHeader('X-Inertia', 'true')->{$method}($url, $data);
        $this->flushHeaders();

        return $response;
    }

    private function draft(array $overrides = []): Invoice
    {
        $this->create($overrides)->assertSessionHasNoErrors();

        return Invoice::query()->where('source', InvoiceSource::Manual->value)->latest('id')->firstOrFail();
    }

    private function send(Invoice $invoice, array $data = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route('workspace.invoices.send', [$this->workspace, $invoice]), ['to' => ['billing@rocketflood.com'], 'cc' => [], 'bcc' => [], ...$data]);
    }

    public function test_a_manual_draft_is_created_with_server_side_totals(): void
    {
        $invoice = $this->draft();

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->status);
        $this->assertSame(InvoiceDisplayStatus::Draft, $invoice->displayStatus());
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->billing_plan_id);
        $this->assertSame($this->rocketFlood->id, $invoice->client_id);
        $this->assertSame(['2026-10-15', '2026-10-30'], [$invoice->issue_date->toDateString(), $invoice->due_date->toDateString()]);
        $this->assertSame([90000, 50000], $invoice->lines()->pluck('amount_minor')->all(), '2 × 450.00 and 1.5 × 333.33 = 499.995 rounded half up once');
        $this->assertSame(140000, $invoice->subtotal_minor);
        $this->assertSame([-14000, 11550], $invoice->adjustments()->pluck('amount_minor')->all(), 'percentages apply to the subtotal, never compound');
        $this->assertSame(137550, $invoice->total_minor);
        $this->assertSame('Thank you for your business.', $invoice->notes);
        $this->assertSame(0, $invoice->workspace->timeEntries()->count(), 'no time entries are needed');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_CREATED->value)->count());
    }

    public function test_a_double_submit_creates_one_draft(): void
    {
        $key = (string) Str::uuid();
        $this->create(['idempotency_key' => $key]);
        $this->create(['idempotency_key' => $key]);

        $this->assertSame(1, Invoice::query()->count());
    }

    public static function invalid(): array
    {
        return [
            'no lines' => [['lines' => []], 'lines'],
            'zero quantity' => [['lines' => [['description' => 'X', 'quantity' => '0', 'rate' => '10']]], 'lines.0.quantity'],
            'three decimals' => [['lines' => [['description' => 'X', 'quantity' => '1', 'rate' => '10.555']]], 'lines.0.rate'],
            'float trick' => [['lines' => [['description' => 'X', 'quantity' => '1e3', 'rate' => '10']]], 'lines.0.quantity'],
            'due before issue' => [['due_date' => '2026-10-01'], 'due_date'],
            'percent over 100' => [['adjustments' => [['kind' => 'discount', 'type' => 'percentage', 'value' => '120']]], 'adjustments.0.value'],
            'no recipient' => [['recipient_type' => 'custom', 'client' => null], 'bill_to.name'],
            'bad currency' => [['currency' => 'BTC'], 'currency'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_manual_invoices_are_validated(array $overrides, string $field): void
    {
        $this->create($overrides)->assertSessionHasErrors($field);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_discounts_larger_than_the_subtotal_are_rejected(): void
    {
        $this->attempt('post', route('workspace.invoices.store', $this->workspace), $this->payload(['adjustments' => [['kind' => 'discount', 'type' => 'fixed', 'value' => '5000']]]))->assertSessionHas('error');
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_custom_recipient_never_touches_client_records(): void
    {
        $clientBefore = $this->rocketFlood->fresh()->only(['name', 'billing_email', 'address', 'tax_id']);

        $invoice = $this->draft([
            'recipient_type' => 'custom',
            'client' => null,
            'bill_to' => ['name' => 'Walk-in Agency LLC', 'billing_email' => 'Ops@WalkIn.example', 'address' => "1 Main St\nAustin TX", 'tax_id' => 'TX-99'],
        ]);

        $this->assertNull($invoice->client_id);
        $this->assertSame(['name' => 'Walk-in Agency LLC', 'billing_email' => 'ops@walkin.example', 'cc_emails' => [], 'address' => "1 Main St\nAustin TX", 'tax_id' => 'TX-99'], $invoice->bill_to);
        $this->assertSame($clientBefore, $this->rocketFlood->fresh()->only(['name', 'billing_email', 'address', 'tax_id']));
    }

    public function test_drafts_can_be_edited_until_finalized(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->owner)->put(route('workspace.invoices.update', [$this->workspace, $invoice]), [
            ...collect($this->payload())->except('idempotency_key')->all(),
            'version' => $invoice->version,
            'lines' => [['description' => 'Single fee', 'quantity' => '1', 'rate' => '1000']],
            'adjustments' => [],
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(100000, $invoice->total_minor);
        $this->assertSame(1, $invoice->lines()->count());

        $this->attempt('put', route('workspace.invoices.update', [$this->workspace, $invoice]), [
            ...collect($this->payload())->except('idempotency_key')->all(),
            'version' => 1,
        ])->assertSessionHas('error');
    }

    public function test_finalizing_numbers_snapshots_and_freezes_the_invoice(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->owner)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-2026-0001', $invoice->number);
        $this->assertSame(['2026-10-15', '2026-10-30'], [$invoice->issue_date->toDateString(), $invoice->due_date->toDateString()], 'the chosen dates are kept');
        $this->assertSame('SprintSync QA', $invoice->bill_from['business_name']);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_ISSUED->value)->count());
        $this->assertSame(0, count($this->smtp->sent), 'finalizing never emails');

        $this->rocketFlood->update(['name' => 'RocketFlood Renamed', 'billing_email' => 'new@rocketflood.com']);
        $this->assertSame('RocketFlood', $invoice->fresh()->bill_to['name'], 'later client edits never change an issued invoice');

        $this->attempt('put', route('workspace.invoices.update', [$this->workspace, $invoice]), [...collect($this->payload())->except('idempotency_key')->all(), 'version' => $invoice->version])->assertSessionHas('error');
    }

    public function test_the_bill_to_snapshot_is_taken_from_the_client_at_finalize(): void
    {
        $invoice = $this->draft();
        $this->rocketFlood->update(['address' => '500 New Address, Miami']);

        $this->actingAs($this->owner)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]));

        $this->assertSame('500 New Address, Miami', $invoice->fresh()->bill_to['address']);
    }

    public function test_finalizing_needs_complete_invoicing_details(): void
    {
        $this->invoicingDetails(['business_name' => '']);
        $invoice = $this->draft();

        $this->attempt('post', route('workspace.invoices.finalize', [$this->workspace, $invoice]))->assertSessionHas('error');
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_pdf_preview_and_download_never_send(): void
    {
        $invoice = $this->draft();

        $draft = $this->actingAs($this->owner)->get(route('workspace.invoices.pdf', [$this->workspace, $invoice]));
        $draft->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename=Draft-RocketFlood-2026-10-15.pdf', $draft->headers->get('Content-Disposition'));

        $preview = $this->actingAs($this->owner)->get(route('workspace.invoices.pdf', [$this->workspace, $invoice, 'preview' => 1]));
        $this->assertStringStartsWith('inline', $preview->headers->get('Content-Disposition'));

        $this->actingAs($this->owner)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]));
        $official = $this->actingAs($this->owner)->get(route('workspace.invoices.pdf', [$this->workspace, $invoice]));
        $official->assertOk();
        $this->assertStringStartsWith('%PDF', $official->streamedContent());
        $this->assertStringContainsString('INV-2026-0001-RocketFlood.pdf', $official->headers->get('Content-Disposition'));

        $this->assertSame(0, count($this->smtp->sent));
        $this->assertNull($invoice->fresh()->delivery);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_DOWNLOADED->value)->count(), 'only the official download is recorded');
    }

    public function test_the_pdf_shows_notes_and_no_period(): void
    {
        $invoice = $this->draft();
        $html = app(InvoicePdfRenderer::class)->html($invoice->load(['lines', 'adjustments']));

        $this->assertStringContainsString('Thank you for your business.', $html);
        $this->assertStringNotContainsString('>Period<', $html);
        $this->assertStringContainsString('Sales tax', $html);
    }

    public function test_sending_to_the_client_billing_email_finalizes_and_queues_one_email(): void
    {
        $invoice = $this->draft();

        $this->send($invoice)->assertSessionHasNoErrors();
        $this->send($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame(InvoiceDisplayStatus::Sent, $invoice->displayStatus());
        $this->assertSame(DeliveryStatus::Sent, $invoice->delivery->status);
        $this->assertCount(1, $this->smtp->sent, 'resubmitting never emails twice');
        $email = $this->smtp->sent[0];
        $this->assertSame(['billing@rocketflood.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('INV-2026-0001-RocketFlood.pdf', $email->getAttachments()[0]->getFilename());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_SEND_QUEUED->value)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_DELIVERY_SENT->value)->count());
    }

    public function test_custom_recipients_cc_bcc_and_message(): void
    {
        $invoice = $this->draft();

        $this->send($invoice, [
            'to' => 'ap@rocketflood.com, ceo@rocketflood.com',
            'cc' => ['accounts@sprintsync.example'],
            'bcc' => ['archive@sprintsync.example'],
            'message' => "Hi team,\nHere is the audit invoice.",
        ])->assertSessionHasNoErrors();

        $email = $this->smtp->sent[0];
        $this->assertSame(['ap@rocketflood.com', 'ceo@rocketflood.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(['accounts@sprintsync.example'], array_map(fn ($a) => $a->getAddress(), $email->getCc()));
        $this->assertSame(['archive@sprintsync.example'], array_map(fn ($a) => $a->getAddress(), $email->getBcc()));
        $this->assertStringContainsString('Here is the audit invoice.', $email->getTextBody());

        $this->assertSame('billing@rocketflood.com', $this->rocketFlood->fresh()->billing_email, 'custom recipients never overwrite the client');
        $this->assertSame([], $this->rocketFlood->fresh()->cc_emails ?? []);
    }

    public function test_send_requires_valid_addresses_and_connected_email(): void
    {
        $invoice = $this->draft();

        $this->send($invoice, ['to' => []])->assertSessionHasErrors('to');
        $this->send($invoice, ['to' => ['not-an-email']])->assertSessionHasErrors('to.0');
        $this->send($invoice, ['bcc' => array_map(fn ($i) => "b{$i}@example.com", range(1, 11))])->assertSessionHasErrors('bcc');

        $this->workspace->mailSettings()->delete();
        $this->attempt('post', route('workspace.invoices.send', [$this->workspace, $invoice]), ['to' => ['billing@rocketflood.com'], 'cc' => [], 'bcc' => []])->assertSessionHas('error');
        $this->assertNull($invoice->fresh()->number, 'nothing is issued when it cannot be sent');
    }

    public function test_mark_paid_and_void_transitions(): void
    {
        $invoice = $this->draft();
        $key = (string) Str::uuid();
        $markPaid = fn () => $this->attempt('post', route('workspace.invoices.mark-paid', [$this->workspace, $invoice]), ['idempotency_key' => $key, 'received_on' => '2026-10-15', 'method' => 'bank_transfer']);

        $markPaid()->assertSessionHas('error');

        $this->actingAs($this->owner)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]));
        $markPaid()->assertSessionHasNoErrors();
        $markPaid();

        $invoice->refresh();
        $this->assertSame(InvoiceDisplayStatus::Paid, $invoice->displayStatus());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(137550, Payment::query()->sole()->amount_minor);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_MARKED_PAID->value)->count());

        $this->attempt('post', route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'Billed in error'])->assertSessionHas('error');
        $this->assertFalse($invoice->fresh()->isVoided(), 'payments must be voided first');

        $this->actingAs($this->owner)->post(route('workspace.invoices.payments.void', [$this->workspace, $invoice, Payment::query()->sole()]), ['reason' => 'Bounced']);
        $this->actingAs($this->owner)->post(route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'Billed in error'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'Again']);

        $invoice->refresh();
        $this->assertSame(InvoiceDisplayStatus::Void, $invoice->displayStatus());
        $this->assertSame('INV-2026-0001', $invoice->number, 'the number is kept on record');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::INVOICE_VOIDED->value)->count());
        $this->assertSame([], app(PaymentTotals::class)->waiting($this->workspace), 'a voided invoice is not owed');
        $this->attempt('post', route('workspace.invoices.send', [$this->workspace, $invoice]), ['to' => ['billing@rocketflood.com'], 'cc' => [], 'bcc' => []])->assertSessionHas('error');
        $this->attempt('post', route('workspace.invoices.mark-paid', [$this->workspace, $invoice]), ['idempotency_key' => (string) Str::uuid(), 'received_on' => '2026-10-15', 'method' => 'bank_transfer'])->assertSessionHas('error');
    }

    public function test_a_draft_can_be_voided_and_never_gets_a_number(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->owner)->post(route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->attempt('post', route('workspace.invoices.finalize', [$this->workspace, $invoice]))->assertSessionHas('error');

        $this->assertNull($invoice->fresh()->number);
    }

    public function test_a_queued_email_for_a_voided_invoice_is_not_sent(): void
    {
        $invoice = $this->draft();
        Queue::fake();
        $this->send($invoice);
        $this->actingAs($this->owner)->post(route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'Wrong client']);

        app(SendInvoiceDeliveryAction::class)->handle($invoice->fresh()->delivery);

        $this->assertCount(0, $this->smtp->sent);
        $this->assertSame(DeliveryStatus::Failed, $invoice->fresh()->delivery->status);
    }

    public function test_plan_invoices_cannot_use_manual_actions(): void
    {
        $plan = $this->devAndOffice();
        $planInvoice = app(GenerateInvoiceFromPlanAction::class)->handle($plan, app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-15')), $this->owner);

        $this->assertSame(InvoiceSource::Plan, $planInvoice->source);
        $this->attempt('post', route('workspace.invoices.finalize', [$this->workspace, $planInvoice]))->assertSessionHas('error');
        $this->attempt('post', route('workspace.invoices.void', [$this->workspace, $planInvoice]), ['reason' => 'x'])->assertSessionHas('error');
        $this->actingAs($this->owner)->get(route('workspace.invoices.show', [$this->workspace, $planInvoice]))->assertInertia(fn (Assert $page) => $page->component('invoices/show'));
    }

    public function test_pages_render(): void
    {
        $invoice = $this->draft();

        $this->actingAs($this->owner)->get(route('workspace.invoices.create', $this->workspace))->assertInertia(fn (Assert $page) => $page->component('invoices/manual-form')->has('clients', 1)->where('invoice', null));
        $this->actingAs($this->owner)->get(route('workspace.invoices.edit', [$this->workspace, $invoice]))->assertInertia(fn (Assert $page) => $page->component('invoices/manual-form')->where('invoice.lines.1.quantity', '1.50')->where('invoice.lines.1.rate', '333.33'));
        $this->actingAs($this->owner)->get(route('workspace.invoices.show', [$this->workspace, $invoice]))->assertInertia(fn (Assert $page) => $page
            ->component('invoices/manual')
            ->where('invoice.summary.display_status', 'draft')
            ->where('canEdit', true)
            ->where('suggestedRecipients.to', ['billing@rocketflood.com']));
        $this->actingAs($this->owner)->get(route('workspace.invoices.index', $this->workspace))->assertInertia(fn (Assert $page) => $page->where('invoices.0.source', 'manual'));
    }

    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_manages_invoices(UserRole $role): void
    {
        $invoice = $this->draft();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->get(route('workspace.invoices.create', $this->workspace))->assertForbidden();
        $this->create([], $user)->assertForbidden();
        $this->actingAs($user)->get(route('workspace.invoices.pdf', [$this->workspace, $invoice]))->assertForbidden();
        $this->actingAs($user)->post(route('workspace.invoices.finalize', [$this->workspace, $invoice]))->assertForbidden();
        $this->send($invoice, [], $user)->assertForbidden();
        $this->actingAs($user)->post(route('workspace.invoices.void', [$this->workspace, $invoice]), ['reason' => 'x'])->assertForbidden();
        $this->assertSame(1, Invoice::query()->count());
        $this->assertNull($invoice->fresh()->number);
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $invoice = $this->draft();
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.invoices.show', [$theirs, $invoice]))->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.invoices.finalize', [$theirs, $invoice]))->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.invoices.store', $theirs), [...$this->payload(), 'client' => $this->rocketFlood->public_id])->assertSessionHasErrors('client');
    }
}
