<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceRecalculator;
use Illuminate\Support\Facades\DB;

/**
 * Approves an invoice and schedules it: Ready to review → Approved, with
 * send_after = now + the cancel window (config finance.approval_send_delay_minutes).
 * It is locked from editing but has no number yet and nothing is sent; the
 * owner can still cancel sending. Issuing, and the number, come later
 * (IssueInvoiceAction).
 *
 * Safe to repeat and to race: the change is a conditional UPDATE on the status
 * and the version the reviewer saw, so a double click approves once (one
 * send_after, one audit entry) and a review of an out-of-date invoice is
 * refused. Approving an invoice that is already approved or issued returns it
 * unchanged.
 */
final class ApproveInvoiceAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly InvoiceRecalculator $recalculator,
    ) {}

    public function handle(Invoice $invoice, int $reviewedVersion, User $actor): Invoice
    {
        try {
            return DB::transaction(fn () => $this->approve($invoice, $reviewedVersion, $actor));
        } catch (InvoiceException $exception) {
            $current = $invoice->fresh();

            if ($current?->status->locksFinancials()) {
                return $current;
            }

            throw $exception;
        }
    }

    private function approve(Invoice $invoice, int $reviewedVersion, User $actor): Invoice
    {
        $invoice = Invoice::query()->with(['lines', 'adjustments', 'workspace'])->findOrFail($invoice->getKey());

        if ($invoice->status->locksFinancials()) {
            return $invoice;
        }

        if (! $invoice->status->canTransitionTo(InvoiceStatus::Approved)) {
            throw InvoiceException::invalidTransition($invoice->status, InvoiceStatus::Approved);
        }

        if ($invoice->version !== $reviewedVersion) {
            throw InvoiceException::changedDuringReview();
        }

        if (! $this->recalculator->recalculate($invoice)) {
            throw InvoiceException::invalidTransition(InvoiceStatus::NeedsHours, InvoiceStatus::Approved);
        }

        $invoice->save();

        $sendAfter = now()->addMinutes((int) config('finance.approval_send_delay_minutes'));

        $claimed = Invoice::query()
            ->whereKey($invoice->getKey())
            ->where('status', InvoiceStatus::ReadyToReview->value)
            ->where('version', $reviewedVersion)
            ->update([
                'status' => InvoiceStatus::Approved->value,
                'version' => $reviewedVersion + 1,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'send_after' => $sendAfter,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw InvoiceException::changedDuringReview();
        }

        $invoice->refresh();
        $label = "\"{$invoice->title}\" for {$invoice->bill_to['name']}";

        $approvedBefore = AuditLog::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->where('action', AuditAction::INVOICE_APPROVED->value)
            ->exists();

        $this->auditLogger->handle(
            $invoice->workspace,
            null,
            $actor,
            $approvedBefore ? AuditAction::INVOICE_REAPPROVED : AuditAction::INVOICE_APPROVED,
            $approvedBefore ? "{$actor->name} approved {$label} again." : "{$actor->name} approved {$label}.",
            $invoice,
            [
                'total_minor' => $invoice->total_minor,
                'currency' => $invoice->currency->value,
                'send_after' => $sendAfter->toIso8601String(),
            ],
        );

        return $invoice;
    }
}
