<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two Invoices-home numbers, per currency (amounts in different
 * currencies are never added together):
 * - waiting: what issued invoices still owe (total minus active payments);
 * - received this month: active payments whose received_on falls in the
 *   workspace's current calendar month (the day money arrived, not the day
 *   it was typed in).
 */
final class PaymentTotals
{
    /** Adds paid_minor (active payments only) to each invoice in the query. */
    public static function withPaid(Builder $query): Builder
    {
        return $query->withSum(['payments as paid_minor' => fn ($payments) => $payments->whereNull('voided_at')], 'amount_minor');
    }

    /**
     * @return list<array{currency: string, amount_minor: int}>
     */
    public function waiting(Workspace $workspace): array
    {
        $owed = [];

        self::withPaid($workspace->invoices()->where('status', InvoiceStatus::Issued->value)->getQuery())
            ->get(['id', 'currency', 'total_minor'])
            ->each(function (Invoice $invoice) use (&$owed) {
                $balance = $invoice->balanceDueMinor();

                if ($balance > 0) {
                    $owed[$invoice->currency->value] = ($owed[$invoice->currency->value] ?? 0) + $balance;
                }
            });

        return self::rows($owed);
    }

    /**
     * @return list<array{currency: string, amount_minor: int}>
     */
    public function receivedThisMonth(Workspace $workspace): array
    {
        $today = CarbonImmutable::now($workspace->timezone);

        $received = Payment::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('voided_at')
            ->whereDate('received_on', '>=', $today->startOfMonth()->toDateString())
            ->whereDate('received_on', '<=', $today->endOfMonth()->toDateString())
            ->groupBy('currency')
            ->selectRaw('currency, sum(amount_minor) as amount_minor')
            ->pluck('amount_minor', 'currency')
            ->map(fn ($amount) => (int) $amount)
            ->all();

        return self::rows($received);
    }

    /**
     * @param  array<string, int>  $byCurrency
     * @return list<array{currency: string, amount_minor: int}>
     */
    private static function rows(array $byCurrency): array
    {
        ksort($byCurrency);

        return array_map(fn (string $currency, int $amount) => ['currency' => $currency, 'amount_minor' => $amount], array_keys($byCurrency), $byCurrency);
    }
}
