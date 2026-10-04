<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Billing\Actions\ProcessAutoSendInvoiceAction;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Makes automatic sending's decision for undecided auto-send invoices (the
 * action waits for each one's send date, except a plan's first invoice): approve them (then the delivery pipeline takes over) or hold
 * them for the owner. Each invoice is decided once.
 */
final class ProcessRecurringInvoicesCommand extends Command
{
    protected $signature = 'billing:process-recurring-invoices';

    protected $description = 'Approve safe auto-send invoices on their send date, or hold them for review';

    public function handle(ProcessAutoSendInvoiceAction $process): int
    {
        $now = CarbonImmutable::now();
        $outcomes = ['approved' => 0, 'held' => 0];

        Invoice::query()
            ->where('delivery_mode', DeliveryMode::AutoSend->value)
            ->whereIn('status', [InvoiceStatus::NeedsHours->value, InvoiceStatus::ReadyToReview->value])
            ->whereNull('automation_checked_at')
            ->whereNotNull('billing_plan_id')
            ->with(['plan.workspace', 'workspace'])
            ->lazyById()
            ->each(function (Invoice $invoice) use ($process, $now, &$outcomes) {
                try {
                    if ($outcome = $process->handle($invoice, $now)) {
                        $outcomes[$outcome]++;
                    }
                } catch (InvoiceException $exception) {
                    $this->warn("{$invoice->title}: {$exception->getMessage()}");
                }
            });

        $this->info("Approved {$outcomes['approved']}, held {$outcomes['held']} for review.");

        return self::SUCCESS;
    }
}
