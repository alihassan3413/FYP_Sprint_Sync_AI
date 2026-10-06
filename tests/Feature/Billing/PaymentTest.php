<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Actions\RecordPaymentAction;
use App\Modules\Billing\Data\PaymentMethod;
use App\Modules\Billing\Data\PaymentStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * Payments against RocketFlood's issued Dev & Office invoice ($5,059.20).
 */
final class PaymentTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
    }

    private function draft(): Invoice
    {
        $plan = $this->devAndOffice();

        return app(GenerateInvoiceFromPlanAction::class)->handle($plan, app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03')), $this->owner);
    }

    private function approved(): Invoice
    {
        $draft = $this->draft();

        return app(ApproveInvoiceAction::class)->handle($draft, $draft->version, $this->owner);
    }

    private function issued(): Invoice
    {
        return app(IssueInvoiceAction::class)->handle($this->approved(), null, sendNow: true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pay(Invoice $invoice, string $amount, array $overrides = [], ?User $as = null): TestResponse
    {
        // Owner requests come from the page (Inertia, so errors flash); others are plain requests.
        $request = $as === null ? $this->actingAs($this->owner)->withHeader('X-Inertia', 'true') : $this->actingAs($as);

        $response = $request
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route('workspace.invoices.payments.store', [$this->workspace, $invoice]), [
                'idempotency_key' => (string) Str::uuid(),
                'amount' => $amount,
                'received_on' => '2026-10-03',
                'method' => 'bank_transfer',
                ...$overrides,
            ]);

        $this->flushHeaders();

        return $response;
    }

    private function voidPayment(Invoice $invoice, Payment $payment, string $reason = 'entered by mistake', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route('workspace.invoices.payments.void', [$this->workspace, $invoice, $payment]), ['reason' => $reason]);
    }

    /**
     * @return array{0: int, 1: int, 2: PaymentStatus|null}
     */
    private function state(Invoice $invoice): array
    {
        $invoice = $invoice->fresh();

        return [$invoice->paidMinor(), $invoice->balanceDueMinor(), $invoice->paymentStatus()];
    }

    /* ---- Recording ------------------------------------------------------ */

    public function test_the_rocketflood_payments_walkthrough(): void
    {
        $invoice = $this->issued();
        $this->assertSame([0, 505920, PaymentStatus::Unpaid], $this->state($invoice));

        $this->pay($invoice, '2000', ['reference' => 'RF-001'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Payment of $2,000.00 recorded.');
        $this->assertSame([200000, 305920, PaymentStatus::PartiallyPaid], $this->state($invoice));

        $this->pay($invoice, '3059.20')->assertSessionHasNoErrors();
        $this->assertSame([505920, 0, PaymentStatus::Paid], $this->state($invoice));

        $first = Payment::query()->where('reference', 'RF-001')->sole();
        $this->voidPayment($invoice, $first)->assertSessionHasNoErrors();
        $this->assertSame([305920, 200000, PaymentStatus::PartiallyPaid], $this->state($invoice));

        $this->pay($invoice, '2,000.00', ['reference' => 'RF-001b'])->assertSessionHasNoErrors();
        $this->assertSame([505920, 0, PaymentStatus::Paid], $this->state($invoice));

        $payment = Payment::query()->where('reference', 'RF-001b')->sole();
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame('2026-10-03', $payment->received_on->toDateString());
        $this->assertSame($this->owner->id, $payment->recorded_by);
        $this->assertSame(3, Payment::query()->count(), 'the voided payment stays in history');
    }

    public function test_payments_are_only_accepted_on_issued_invoices(): void
    {
        $draft = $this->draft();
        $this->pay($draft, '100')->assertSessionHas('error');

        $approved = app(ApproveInvoiceAction::class)->handle($draft, $draft->version, $this->owner);
        $this->pay($approved, '100')->assertSessionHas('error');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_overpaying_is_refused_without_capping(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '4059.20');

        $this->pay($invoice, '1100')->assertSessionHas('error');
        $this->assertSame([405920, 100000, PaymentStatus::PartiallyPaid], $this->state($invoice));

        $this->pay($invoice, '1000')->assertSessionHasNoErrors();
        $this->pay($invoice, '0.01')->assertSessionHas('error', fn ($error) => $error['message'] === 'This invoice is already fully paid.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayments(): array
    {
        return [
            'zero' => [['amount' => '0'], 'amount'],
            'negative' => [['amount' => '-5'], 'amount'],
            'three decimals' => [['amount' => '10.005'], 'amount'],
            'missing date' => [['received_on' => ''], 'received_on'],
            'future date' => [['received_on' => '2026-10-04'], 'received_on'],
            'bad date' => [['received_on' => '03/10/2026'], 'received_on'],
            'unknown method' => [['method' => 'bitcoin'], 'method'],
            'missing key' => [['idempotency_key' => ''], 'idempotency_key'],
            'long reference' => [['reference' => str_repeat('x', 101)], 'reference'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayments')]
    public function test_payment_input_is_validated(array $overrides, string $field): void
    {
        $invoice = $this->issued();

        $this->pay($invoice, $overrides['amount'] ?? '100', $overrides)->assertSessionHasErrors($field);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_currency_is_always_the_invoices(): void
    {
        $invoice = $this->issued();

        $this->pay($invoice, '100', ['currency' => 'PKR', 'workspace_id' => 999, 'voided_at' => now()])->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $this->assertSame('USD', $payment->currency->value);
        $this->assertSame($this->workspace->id, $payment->workspace_id);
        $this->assertNull($payment->voided_at);
    }

    public function test_received_on_uses_the_workspace_calendar(): void
    {
        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $invoice = $this->issued();

        // 21:00 UTC on Oct 3 is already Oct 4 in Lahore, so Oct 4 is not in the future there.
        Carbon::setTestNow('2026-10-03 21:00:00');
        $this->pay($invoice, '100', ['received_on' => '2026-10-04'])->assertSessionHasNoErrors();
    }

    /* ---- Idempotency ---------------------------------------------------- */

    public function test_a_retried_or_double_clicked_submission_records_one_payment(): void
    {
        $invoice = $this->issued();
        $key = (string) Str::uuid();

        $this->pay($invoice, '2000', ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->pay($invoice, '2000', ['idempotency_key' => $key])->assertSessionHasNoErrors();
        app(RecordPaymentAction::class)->handle($invoice, $key, 200000, CarbonImmutable::parse('2026-10-03'), PaymentMethod::Cash, null, null, $this->owner);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(200000, $invoice->fresh()->paidMinor());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PAYMENT_RECORDED->value)->count());
    }

    public function test_the_database_refuses_a_reused_key(): void
    {
        $invoice = $this->issued();
        $key = (string) Str::uuid();
        $this->pay($invoice, '100', ['idempotency_key' => $key]);

        $this->expectException(UniqueConstraintViolationException::class);

        $invoice->payments()->forceCreate([
            'workspace_id' => $this->workspace->id,
            'idempotency_key' => $key,
            'amount_minor' => 1,
            'currency' => 'USD',
            'received_on' => '2026-10-03',
            'method' => 'cash',
        ]);
    }

    /* ---- Voiding -------------------------------------------------------- */

    public function test_voiding_keeps_the_record_and_is_idempotent(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');
        $payment = Payment::query()->sole();

        $this->voidPayment($invoice, $payment)
            ->assertSessionHas('success', 'Payment voided. It stays in the history but no longer counts as paid.');
        $voidedAt = $payment->fresh()->voided_at;

        $this->travel(5)->minutes();
        $this->voidPayment($invoice, $payment, 'second click')->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame($voidedAt->toDateTimeString(), $payment->voided_at->toDateTimeString());
        $this->assertSame('entered by mistake', $payment->void_reason);
        $this->assertSame($this->owner->id, $payment->voided_by);
        $this->assertSame(200000, $payment->amount_minor);
        $this->assertSame(0, $invoice->fresh()->paidMinor());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PAYMENT_VOIDED->value)->count());
    }

    public function test_a_void_needs_a_reason(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');

        $this->voidPayment($invoice, Payment::query()->sole(), '')->assertSessionHasErrors('reason');

        $this->assertNull(Payment::query()->sole()->voided_at);
    }

    public function test_payments_cannot_be_edited_or_deleted(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');
        $payment = Payment::query()->sole();

        try {
            $payment->forceFill(['amount_minor' => 1])->save();
            $this->fail('Payment amounts must not change.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $payment->delete();
    }

    public function test_audit_entries_carry_the_essentials_but_not_the_note(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000', ['reference' => 'RF-001', 'note' => 'private remark']);

        $entry = AuditLog::query()->where('action', AuditAction::PAYMENT_RECORDED->value)->sole();
        $this->assertSame('INV-2026-0001', $entry->metadata['invoice']);
        $this->assertSame(200000, $entry->metadata['amount_minor']);
        $this->assertSame('2026-10-03', $entry->metadata['received_on']);
        $this->assertSame('bank_transfer', $entry->metadata['method']);
        $this->assertSame('RF-001', $entry->metadata['reference']);
        $this->assertArrayNotHasKey('note', $entry->metadata);
    }

    /* ---- Invoice integrity ------------------------------------------------ */

    public function test_payments_never_change_the_invoice_or_its_official_pdf(): void
    {
        $invoice = $this->issued();
        $before = $invoice->fresh()->only(['number', 'total_minor', 'subtotal_minor', 'adjustments_minor', 'bill_to', 'bill_from', 'status', 'version', 'updated_at']);
        $pdf = Storage::disk('local')->get($invoice->pdf_path);

        $this->pay($invoice, '2000');
        $this->voidPayment($invoice, Payment::query()->sole());
        $this->pay($invoice, '5059.20');

        $after = $invoice->fresh();
        $this->assertEquals($before, $after->only(array_keys($before)));
        $this->assertSame([200000, 120000, 30000, 110000, 18000, 18000], $after->lines()->pluck('amount_minor')->all());
        $this->assertSame($pdf, Storage::disk('local')->get($after->pdf_path));
    }

    /* ---- Pages ---------------------------------------------------------- */

    public function test_the_invoice_page_shows_payments_and_balance(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000', ['reference' => 'RF-001']);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.summary.payment_status', 'partially_paid')
                ->where('invoice.summary.paid_minor', 200000)
                ->where('invoice.summary.balance_due_minor', 305920)
                ->where('invoice.payments.0.reference', 'RF-001')
                ->where('invoice.payments.0.method_label', 'Bank transfer')
                ->missing('invoice.payments.0.id')
                ->missing('invoice.payments.0.idempotency_key')
                ->where('canRecordPayments', true)
                ->where('today', '2026-10-03'));
    }

    public function test_the_invoices_home_shows_waiting_and_received_this_month(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000', ['received_on' => '2026-10-02']);
        $this->pay($invoice, '59.20', ['received_on' => '2026-09-30']);
        $this->pay($invoice, '1000', ['received_on' => '2026-10-01']);
        $this->voidPayment($invoice, Payment::query()->where('amount_minor', 100000)->sole());

        // A draft and an approved-but-not-issued invoice owe nothing yet.
        $csr = $this->csrTeam();
        app(GenerateInvoiceFromPlanAction::class)->handle($csr, app(BillingSchedule::class)->latestDueCycle($csr, CarbonImmutable::parse('2026-10-03')), null);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->where('waitingToBePaid', [['currency' => 'USD', 'amount_minor' => 505920 - 200000 - 5920]])
                ->where('receivedThisMonth', [['currency' => 'USD', 'amount_minor' => 200000]])
                ->where('invoices', fn ($rows) => collect($rows)->firstWhere('number', 'INV-2026-0001')['payment_status'] === 'partially_paid'));
    }

    public function test_received_this_month_follows_the_workspace_month(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '100', ['received_on' => '2026-10-31']);

        Carbon::setTestNow('2026-10-31 20:00:00');
        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();

        // It is already November in Lahore, so October's payment no longer counts.
        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('receivedThisMonth', []));
    }

    public function test_paid_invoices_are_not_waiting(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '5059.20');

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('waitingToBePaid', []));
    }

    public function test_the_client_page_lists_its_invoices_with_their_balance(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $this->rocketFlood]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoices.0.number', 'INV-2026-0001')
                ->where('invoices.0.payment_status_label', 'Partially paid')
                ->where('invoices.0.balance_due_minor', 305920));
    }

    /* ---- Access ----------------------------------------------------------- */

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_can_record_or_void(UserRole $role): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');
        $payment = Payment::query()->sole();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->pay($invoice, '100', as: $user)->assertForbidden();
        $this->voidPayment($invoice, $payment, as: $user)->assertForbidden();

        $this->assertSame(1, Payment::query()->count());
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_other_workspaces_and_numeric_ids_get_not_found(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');
        $payment = Payment::query()->sole();
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->pay($invoice, '100', as: $outsider)->assertNotFound();
        $this->voidPayment($invoice, $payment, as: $outsider)->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.invoices.payments.void', [$theirs, $invoice, $payment]), ['reason' => 'x'])->assertNotFound();

        $this->actingAs($this->owner)
            ->post("/{$this->workspace->slug}/invoices/{$invoice->public_id}/payments/{$payment->id}/void", ['reason' => 'x'])
            ->assertNotFound();

        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_a_payment_from_another_invoice_cannot_be_voided_through_this_one(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, '2000');
        $payment = Payment::query()->sole();

        $csr = $this->csrTeam();
        $other = app(GenerateInvoiceFromPlanAction::class)->handle($csr, app(BillingSchedule::class)->latestDueCycle($csr, CarbonImmutable::parse('2026-10-03')), null);

        $this->voidPayment($other, $payment)->assertNotFound();
        $this->assertNull($payment->fresh()->voided_at);
    }
}
