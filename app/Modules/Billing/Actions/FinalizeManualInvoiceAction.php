<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceNumberAllocator;
use App\Modules\Billing\Support\OfficialInvoicePdf;
use App\Modules\Billing\Support\SenderSnapshot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class FinalizeManualInvoiceAction
{
    public function __construct(
        private readonly InvoiceNumberAllocator $numbers,
        private readonly OfficialInvoicePdf $pdf,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        $issued = retry(3, fn () => DB::transaction(function () use ($invoice, $actor) {
            $locked = Invoice::query()->with(['workspace.invoicingProfile', 'client'])->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $locked->isManual()) {
                throw InvoiceException::notManual();
            }

            if ($locked->isVoided()) {
                throw InvoiceException::voided();
            }

            if ($locked->isIssued()) {
                return null;
            }

            if ($locked->status !== InvoiceStatus::ReadyToReview) {
                throw InvoiceException::locked($locked->status);
            }

            $sender = SenderSnapshot::from($locked->workspace->invoicingProfile);

            if (! SenderSnapshot::isComplete($sender)) {
                throw InvoiceException::senderIncomplete('sending');
            }

            $number = $this->numbers->next($locked->workspace, $locked->issue_date->year);

            $finalized = Invoice::query()
                ->whereKey($locked->getKey())
                ->where('status', InvoiceStatus::ReadyToReview->value)
                ->whereNull('number')
                ->whereNull('voided_at')
                ->update([
                    'status' => InvoiceStatus::Issued->value,
                    'number' => $number,
                    'version' => $locked->version + 1,
                    'bill_to' => json_encode($locked->client?->billingSnapshot() ?? $locked->bill_to),
                    'bill_from' => json_encode($sender),
                    'approved_at' => now(),
                    'approved_by' => $actor->id,
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($finalized !== 1) {
                return null;
            }

            $this->auditLogger->handle(
                $locked->workspace,
                null,
                $actor,
                AuditAction::INVOICE_ISSUED,
                "{$actor->name} finalized invoice {$number} for {$locked->bill_to['name']}.",
                $locked,
                ['number' => $number, 'total_minor' => $locked->total_minor, 'currency' => $locked->currency->value],
            );

            return $locked->getKey();
        }), 0, fn ($exception) => $exception instanceof UniqueConstraintViolationException);

        $fresh = Invoice::query()->findOrFail($invoice->getKey());

        if ($issued !== null) {
            $this->pdf->ensureSafely($fresh);
        }

        return $fresh;
    }
}
