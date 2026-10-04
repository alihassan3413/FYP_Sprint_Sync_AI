<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\SystemActor;
use App\Modules\Billing\Actions\PrepareInvoiceDeliveryAction;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\DeliveryException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Jobs\SendInvoiceEmailJob;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;
use Illuminate\Console\Command;

/**
 * Runs every minute. Safe to run twice at once and to rerun: every step is a
 * conditional update or a unique row, so a second pass finds nothing to do.
 *
 * 1. Approved invoices whose cancel window has passed are issued and get their
 *    delivery record (skipped while the workspace has no email connected).
 * 2. Pending deliveries are queued (the job's claim makes duplicates harmless).
 * 3. A delivery stuck in "sending" (the worker died mid-send) cannot be known
 *    to have failed, so it becomes "needs attention" rather than being resent.
 */
final class DispatchApprovedInvoicesCommand extends Command
{
    /** How long a send may take before it is treated as interrupted. */
    public const STUCK_AFTER_MINUTES = 10;

    protected $signature = 'billing:dispatch-approved-invoices';

    protected $description = 'Issue approved invoices whose cancel window has passed and queue their emails';

    public function handle(PrepareInvoiceDeliveryAction $prepare, RecordAuditLogAction $audit): int
    {
        Invoice::query()
            ->where('status', InvoiceStatus::Approved->value)
            ->where('send_after', '<=', now())
            ->whereHas('workspace.mailSettings')
            ->with('workspace.mailSettings')
            ->limit(100)
            ->get()
            ->each(function (Invoice $invoice) use ($prepare) {
                try {
                    $prepare->handle($invoice, null, sendNow: false);
                } catch (InvoiceException|DeliveryException) {
                    // Cancelled, or settings removed, between the query and now: nothing to send.
                }
            });

        InvoiceDelivery::query()
            ->where('status', DeliveryStatus::Pending->value)
            ->pluck('id')
            ->each(fn (int $id) => SendInvoiceEmailJob::dispatch($id));

        InvoiceDelivery::query()
            ->where('status', DeliveryStatus::Sending->value)
            ->where('last_attempted_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->with('invoice.workspace')
            ->get()
            ->each(function (InvoiceDelivery $delivery) use ($audit) {
                $moved = InvoiceDelivery::query()
                    ->whereKey($delivery->getKey())
                    ->where('status', DeliveryStatus::Sending->value)
                    ->update(['status' => DeliveryStatus::NeedsAttention->value, 'error' => 'Sending was interrupted, so it is not known whether the email went out.', 'failed_at' => now()]);

                if ($moved === 1) {
                    $audit->handle($delivery->invoice->workspace, null, new SystemActor, AuditAction::INVOICE_DELIVERY_UNCERTAIN, "Sending invoice {$delivery->invoice->number} was interrupted.", $delivery, ['invoice' => $delivery->invoice->number]);
                }
            });

        return self::SUCCESS;
    }
}
