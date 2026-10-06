<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\FxRate;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\ExchangeRate;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\WorkspaceFinanceSettings;
use App\Modules\Billing\Support\CurrencyConverter;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Finance overview: invoiced, received and expenses in a period, converted
 * exactly per record with stored rates, never by adding currencies together.
 * The reference case: USD 10,000 invoiced, PKR 3,000,000 of expenses, and
 * 1 USD = 280.50 PKR.
 */
final class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create(['timezone' => 'Asia/Karachi']);
        $this->client = Client::factory()->for($this->workspace)->create();
    }

    private function invoice(int $totalMinor, string $issueDate, Currency $currency = Currency::USD, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
    {
        return Invoice::factory()->for($this->client)->create([
            'status' => $status,
            'currency' => $currency,
            'total_minor' => $totalMinor,
            'subtotal_minor' => $totalMinor,
            'issue_date' => $status === InvoiceStatus::Issued ? $issueDate : null,
            'number' => $status === InvoiceStatus::Issued ? 'INV-'.Str::random(6) : null,
        ]);
    }

    private function payment(Invoice $invoice, int $amountMinor, string $receivedOn, bool $voided = false): Payment
    {
        return Payment::query()->forceCreate([
            'workspace_id' => $this->workspace->id,
            'invoice_id' => $invoice->id,
            'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => $amountMinor,
            'currency' => $invoice->currency,
            'received_on' => $receivedOn,
            'method' => 'bank_transfer',
            'voided_at' => $voided ? now() : null,
        ]);
    }

    private function expense(int $amountMinor, Currency $currency, string $date, bool $voided = false): Expense
    {
        return Expense::query()->forceCreate([
            'workspace_id' => $this->workspace->id,
            'category' => 'office_rent',
            'description' => 'Rent',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'expense_date' => $date,
            'voided_at' => $voided ? now() : null,
        ]);
    }

    private function rate(string $base, string $quote, string $rate, string $date = '2026-10-01', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.finance.overview', $this->workspace))
            ->post(route('workspace.finance.exchange-rates.store', $this->workspace), ['base_currency' => $base, 'quote_currency' => $quote, 'rate' => $rate, 'rate_date' => $date]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function overview(array $query = []): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('workspace.finance.overview', [$this->workspace, ...$query]));
    }

    private function reference(): void
    {
        $this->invoice(1000000, '2026-10-05');
        $this->expense(300000000, Currency::PKR, '2026-10-01');
    }

    public function test_the_reference_case_converts_exactly(): void
    {
        $this->reference();
        $this->rate('USD', 'PKR', '280.50')->assertSessionHasNoErrors();

        // 3,000,000 / 280.5 = 10,695.187…, rounded once to $10,695.19.
        $this->overview()->assertInertia(fn (Assert $page) => $page
            ->component('finance/overview')
            ->where('displayCurrency', 'USD')
            ->where('overview.converted.invoiced', '$10,000.00')
            ->where('overview.converted.expenses', '$10,695.19')
            ->where('overview.converted.invoice_result', '−$695.19')
            ->where('overview.converted.invoice_result_negative', true)
            ->where('overview.converted.received', '$0.00')
            ->where('overview.converted.cash_result', '−$10,695.19')
            ->where('overview.missing_rates', [])
            ->where('overview.native.0.currency', 'PKR')
            ->where('overview.native.0.expenses', 'PKR 3,000,000.00')
            ->where('overview.native.1.currency', 'USD')
            ->where('overview.native.1.invoiced', '$10,000.00'));

        $this->overview(['currency' => 'PKR'])->assertInertia(fn (Assert $page) => $page
            ->where('displayCurrency', 'PKR')
            ->where('overview.converted.invoiced', 'PKR 2,805,000.00')
            ->where('overview.converted.expenses', 'PKR 3,000,000.00')
            ->where('overview.converted.invoice_result', '−PKR 195,000.00'));

        $this->assertSame(Currency::USD, WorkspaceFinanceSettings::reportingCurrency($this->workspace->id), 'viewing in PKR changes no setting');
    }

    public function test_a_missing_rate_is_shown_never_guessed(): void
    {
        $this->reference();

        $this->overview()->assertInertia(fn (Assert $page) => $page
            ->where('overview.converted.invoiced', '$10,000.00')
            ->where('overview.converted.expenses', null)
            ->where('overview.converted.invoice_result', null)
            ->where('overview.converted.cash_result', null)
            ->where('overview.missing_rates', [['from' => 'PKR', 'to' => 'USD', 'earliest' => '2026-10-01', 'records' => 1]]));

        $this->assertSame(0, DB::table('fx_snapshots')->count());
    }

    public function test_a_rate_dated_after_the_record_does_not_apply(): void
    {
        $this->reference();
        $this->rate('USD', 'PKR', '280.50', '2026-10-02');

        $this->overview()->assertInertia(fn (Assert $page) => $page->where('overview.converted.expenses', null));
    }

    public function test_past_results_keep_the_rate_they_were_converted_with(): void
    {
        $this->reference();
        $this->rate('USD', 'PKR', '280.50');
        $this->overview();

        $this->rate('USD', 'PKR', '300', '2026-10-01');

        $this->overview()->assertInertia(fn (Assert $page) => $page->where('overview.converted.expenses', '$10,695.19'));
        $this->assertSame(1, DB::table('fx_snapshots')->count());

        $this->expense(300000000, Currency::PKR, '2026-10-10');
        $this->overview()->assertInertia(fn (Assert $page) => $page->where('overview.converted.expenses', '$20,695.19'), 'a new record uses the newer rate');
    }

    public function test_only_issued_invoices_active_payments_and_active_expenses_count(): void
    {
        $issued = $this->invoice(100000, '2026-10-02');
        $this->invoice(999900, '2026-10-02', status: InvoiceStatus::ReadyToReview);
        $this->invoice(999900, '2026-10-02', status: InvoiceStatus::Approved);
        $this->payment($issued, 40000, '2026-10-03');
        $this->payment($issued, 60000, '2026-10-03', voided: true);
        $this->expense(25000, Currency::USD, '2026-10-04');
        $this->expense(77700, Currency::USD, '2026-10-04', voided: true);

        $this->overview()->assertInertia(fn (Assert $page) => $page
            ->where('overview.converted.invoiced', '$1,000.00')
            ->where('overview.converted.received', '$400.00')
            ->where('overview.converted.expenses', '$250.00')
            ->where('overview.converted.invoice_result', '$750.00')
            ->where('overview.converted.cash_result', '$150.00'));
    }

    public function test_periods_follow_the_workspace_calendar(): void
    {
        $this->invoice(100000, '2026-09-30');
        $this->invoice(200000, '2026-10-01');
        $this->invoice(400000, '2026-11-01');

        $this->overview()->assertInertia(fn (Assert $page) => $page->where('period.key', 'this_month')->where('overview.converted.invoiced', '$2,000.00'));
        $this->overview(['period' => 'last_month'])->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-09-01')->where('period.to', '2026-09-30')->where('overview.converted.invoiced', '$1,000.00'));
        $this->overview(['period' => 'custom', 'from' => '2026-09-15', 'to' => '2026-11-01'])->assertInertia(fn (Assert $page) => $page->where('overview.converted.invoiced', '$7,000.00'));
        $this->overview(['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-09-01'])->assertInertia(fn (Assert $page) => $page->where('period.key', 'this_month'));
        $this->overview(['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'])->assertInertia(fn (Assert $page) => $page->where('period.key', 'this_month'));

        // 20:00 UTC on Oct 31 is Nov 1 in Karachi: "this month" is November.
        $this->travelTo('2026-10-31 20:00:00');
        $this->overview()->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-11-01')->where('overview.converted.invoiced', '$4,000.00'));
    }

    public function test_an_inverse_rate_is_used_exactly(): void
    {
        $this->expense(1000, Currency::USD, '2026-10-02');
        $this->rate('PKR', 'USD', '0.003565062389')->assertSessionHasNoErrors();

        $this->overview(['currency' => 'PKR'])->assertInertia(fn (Assert $page) => $page->where('overview.converted.expenses', 'PKR 2,805.00'));
    }

    public function test_conversion_is_integer_exact(): void
    {
        $rate = new FxRate(Currency::PKR, Currency::USD, '0.0035650623885918', '2026-10-01', 'manual');

        $this->assertSame(1069519, CurrencyConverter::convertMinor(300000000, $rate));
        $this->assertSame(0, CurrencyConverter::convertMinor(1, $rate));
        $this->assertSame(PHP_INT_MAX, CurrencyConverter::convertMinor(PHP_INT_MAX, new FxRate(Currency::USD, Currency::USD, '1', '2026-10-01', 'same')));
    }

    public function test_rates_are_audited_normalised_and_never_edited(): void
    {
        $this->rate('USD', 'PKR', '280.500')->assertSessionHasNoErrors();

        $rate = ExchangeRate::query()->sole();
        $this->assertSame('280.5', $rate->rate);
        $this->assertSame($this->owner->id, $rate->created_by);
        $this->assertStringContainsString('1 USD = 280.5 PKR', AuditLog::query()->where('action', AuditAction::FX_RATE_ENTERED->value)->sole()->description);

        $this->expectException(\LogicException::class);
        $rate->update(['rate' => '1']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function invalidRates(): array
    {
        return [
            'zero' => ['USD', 'PKR', '0', 'rate'],
            'zero decimals' => ['USD', 'PKR', '0.000', 'rate'],
            'negative' => ['USD', 'PKR', '-280', 'rate'],
            'scientific' => ['USD', 'PKR', '2.8e2', 'rate'],
            'too precise' => ['USD', 'PKR', '1.1234567890123', 'rate'],
            'same currency' => ['USD', 'USD', '1', 'quote_currency'],
            'unknown currency' => ['USD', 'BTC', '1', 'quote_currency'],
        ];
    }

    #[DataProvider('invalidRates')]
    public function test_rates_are_validated(string $base, string $quote, string $rate, string $field): void
    {
        $this->rate($base, $quote, $rate)->assertSessionHasErrors($field);
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_the_reporting_currency_change_is_audited_and_converts_nothing_stored(): void
    {
        $this->expense(30000000, Currency::PKR, '2026-10-01');

        $this->actingAs($this->owner)->put(route('workspace.finance.reporting-currency', $this->workspace), ['currency' => 'PKR'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('workspace.finance.reporting-currency', $this->workspace), ['currency' => 'PKR']);

        $this->assertSame(Currency::PKR, WorkspaceFinanceSettings::reportingCurrency($this->workspace->id));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::FINANCE_REPORTING_CURRENCY_CHANGED->value)->count());
        $this->assertSame(30000000, Expense::query()->sole()->amount_minor);

        $this->overview()->assertInertia(fn (Assert $page) => $page->where('displayCurrency', 'PKR')->where('overview.converted.expenses', 'PKR 300,000.00'));
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_sees_the_overview_and_manages_rates(UserRole $role): void
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->get(route('workspace.finance.overview', $this->workspace))->assertForbidden();
        $this->rate('USD', 'PKR', '280', as: $user)->assertForbidden();
        $this->actingAs($user)->put(route('workspace.finance.reporting-currency', $this->workspace), ['currency' => 'PKR'])->assertForbidden();

        $this->assertSame(0, ExchangeRate::query()->count());
        $this->assertSame(0, WorkspaceFinanceSettings::query()->count());
    }

    public function test_other_workspaces_get_not_found_and_never_share_rates(): void
    {
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.finance.overview', $this->workspace))->assertNotFound();

        $this->actingAs($outsider)->post(route('workspace.finance.exchange-rates.store', $theirs), ['base_currency' => 'USD', 'quote_currency' => 'PKR', 'rate' => '280.5', 'rate_date' => '2026-10-01']);
        $this->reference();

        $this->overview()->assertInertia(fn (Assert $page) => $page->where('overview.converted.expenses', null)->where('rates', []));
    }
}
