<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * "Cancel sending": Approved → Ready to review, so the owner can fix the
 * invoice and approve it again. Only possible before it is issued. Nothing is
 * deleted and no number was ever used; the approval itself stays in the audit
 * log, while approved_at, approved_by and send_after are cleared.
 *
 * A conditional UPDATE (only from Approved) makes a double click or retry do
 * nothing the second time, without a second audit entry.
 */
final class CancelInvoiceSendingAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        $reopened = Invoice::query()
            ->whereKey($invoice->getKey())
            ->where('status', InvoiceStatus::Approved->value)
            ->whereNull('issued_at')
            ->update([
                'status' => InvoiceStatus::ReadyToReview->value,
                'version' => DB::raw('version + 1'),
                'approved_at' => null,
                'approved_by' => null,
                'send_after' => null,
                'updated_at' => now(),
            ]);

        $invoice->refresh();

        if ($reopened === 1) {
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_SEND_CANCELLED,
                "{$actor->name} cancelled sending \"{$invoice->title}\" for {$invoice->bill_to['name']} to make changes.",
                $invoice,
            );

            return $invoice;
        }

        if ($invoice->isIssued()) {
            throw InvoiceException::alreadyIssued();
        }

        if ($invoice->status !== InvoiceStatus::ReadyToReview) {
            throw InvoiceException::invalidTransition($invoice->status, InvoiceStatus::ReadyToReview);
        }

        return $invoice;
    }
}
