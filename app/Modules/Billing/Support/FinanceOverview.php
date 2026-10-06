<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\Money;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Money in and out for a period of workspace calendar days:
 *
 * - invoiced: issued invoices by issue date (drafts and approved-but-unsent
 *   never count);
 * - received: payments not voided, by the date the money arrived;
 * - expenses: expenses not voided, by expense date.
 *
 * Amounts are only ever added within one currency. A converted total is the
 * sum of each record converted on its own date (CurrencyConverter); if any
 * record has no rate, that total is null and the missing rate is listed,
 * nothing is guessed. Results are before team compensation.
 */
final class FinanceOverview
{
    private const METRICS = ['invoiced', 'received', 'expenses'];

    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * @return array{
     *     native: list<array{currency: string, invoiced: string, received: string, expenses: string, invoice_result: string, cash_result: string}>,
     *     converted: array{invoiced: ?string, received: ?string, expenses: ?string, invoice_result: ?string, cash_result: ?string, invoice_result_negative: bool, cash_result_negative: bool},
     *     missing_rates: list<array{from: string, to: string, earliest: string, records: int}>
     * }
     */
    public function handle(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, Currency $display): array
    {
        $records = [
            'invoiced' => Invoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', InvoiceStatus::Issued)
                ->whereDate('issue_date', '>=', $from->toDateString())
                ->whereDate('issue_date', '<=', $to->toDateString())
                ->get(['id', 'total_minor', 'currency', 'issue_date'])
                ->map(fn (Invoice $invoice) => ['type' => 'invoice', 'id' => $invoice->id, 'money' => Money::of($invoice->total_minor, $invoice->currency), 'date' => $invoice->issue_date]),
            'received' => Payment::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('voided_at')
                ->whereDate('received_on', '>=', $from->toDateString())
                ->whereDate('received_on', '<=', $to->toDateString())
                ->get(['id', 'amount_minor', 'currency', 'received_on'])
                ->map(fn (Payment $payment) => ['type' => 'payment', 'id' => $payment->id, 'money' => Money::of($payment->amount_minor, $payment->currency), 'date' => $payment->received_on]),
            'expenses' => Expense::query()
                ->where('workspace_id', $workspace->id)
                ->active()
                ->whereDate('expense_date', '>=', $from->toDateString())
                ->whereDate('expense_date', '<=', $to->toDateString())
                ->get(['id', 'amount_minor', 'currency', 'expense_date'])
                ->map(fn (Expense $expense) => ['type' => 'expense', 'id' => $expense->id, 'money' => $expense->money(), 'date' => $expense->expense_date]),
        ];

        $missing = [];
        $converted = [];

        foreach (self::METRICS as $metric) {
            $converted[$metric] = $this->convertedTotal($workspace, $records[$metric], $display, $missing);
        }

        $invoiceResult = $converted['invoiced'] !== null && $converted['expenses'] !== null ? $converted['invoiced']->minus($converted['expenses']) : null;
        $cashResult = $converted['received'] !== null && $converted['expenses'] !== null ? $converted['received']->minus($converted['expenses']) : null;

        ksort($missing);

        return [
            'native' => $this->nativeRows($records),
            'converted' => [
                'invoiced' => $converted['invoiced']?->formatted(),
                'received' => $converted['received']?->formatted(),
                'expenses' => $converted['expenses']?->formatted(),
                'invoice_result' => $invoiceResult?->formatted(),
                'cash_result' => $cashResult?->formatted(),
                'invoice_result_negative' => $invoiceResult?->isNegative() ?? false,
                'cash_result_negative' => $cashResult?->isNegative() ?? false,
            ],
            'missing_rates' => array_values($missing),
        ];
    }

    /**
     * ponytail: one snapshot lookup per record; batch the lookups if a period ever holds thousands of records.
     *
     * @param  Collection<int, array{type: string, id: int, money: Money, date: \DateTimeInterface}>  $records
     * @param  array<string, array{from: string, to: string, earliest: string, records: int}>  $missing
     */
    private function convertedTotal(Workspace $workspace, Collection $records, Currency $display, array &$missing): ?Money
    {
        $total = Money::zero($display);
        $complete = true;

        foreach ($records as $record) {
            $date = CarbonImmutable::parse($record['date']->format('Y-m-d'));
            $amount = $this->converter->convert($workspace->id, $record['type'], $record['id'], $record['money'], $display, $date);

            if ($amount === null) {
                $complete = false;
                $key = $record['money']->currency->value;
                $missing[$key] ??= ['from' => $key, 'to' => $display->value, 'earliest' => $date->toDateString(), 'records' => 0];
                $missing[$key]['records']++;
                $missing[$key]['earliest'] = min($missing[$key]['earliest'], $date->toDateString());

                continue;
            }

            $total = $total->plus($amount);
        }

        return $complete ? $total : null;
    }

    /**
     * @param  array<string, Collection<int, array{money: Money}>>  $records
     * @return list<array{currency: string, invoiced: string, received: string, expenses: string, invoice_result: string, cash_result: string}>
     */
    private function nativeRows(array $records): array
    {
        $sums = [];

        foreach ($records as $metric => $rows) {
            foreach ($rows as $row) {
                $currency = $row['money']->currency;
                $sums[$currency->value] ??= array_fill_keys(self::METRICS, Money::zero($currency));
                $sums[$currency->value][$metric] = $sums[$currency->value][$metric]->plus($row['money']);
            }
        }

        ksort($sums);

        return array_values(array_map(fn (string $currency, array $sum) => [
            'currency' => $currency,
            'invoiced' => $sum['invoiced']->formatted(),
            'received' => $sum['received']->formatted(),
            'expenses' => $sum['expenses']->formatted(),
            'invoice_result' => $sum['invoiced']->minus($sum['expenses'])->formatted(),
            'cash_result' => $sum['received']->minus($sum['expenses'])->formatted(),
        ], array_keys($sums), $sums));
    }
}
