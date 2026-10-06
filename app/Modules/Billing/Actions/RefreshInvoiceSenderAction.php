<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\SenderSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The one explicit way a draft picks up newer invoicing details: the owner
 * asks for it ("Use current invoicing details"). Only while the invoice can
 * still be edited; approved and issued invoices keep their sender.
 */
final class RefreshInvoiceSenderAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->with('workspace.invoicingProfile')->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $invoice->status->isEditable()) {
                throw InvoiceException::locked($invoice->status);
            }

            $sender = SenderSnapshot::from($invoice->workspace->invoicingProfile);

            if ($sender === $invoice->bill_from) {
                return $invoice;
            }

            $invoice->forceFill(['bill_from' => $sender, 'version' => $invoice->version + 1])->save();

            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_UPDATED,
                "{$actor->name} updated the sender details on \"{$invoice->title}\" for {$invoice->bill_to['name']}.",
                $invoice,
                ['changed' => ['bill_from']],
            );

            return $invoice;
        });
    }
}
