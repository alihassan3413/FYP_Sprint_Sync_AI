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
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues an approved invoice: Approved → Issued. This is the one place the
 * business number (INV-2026-0001) is assigned, and after it nothing about the
 * invoice can change. It does not send email; the delivery step that will call
 * this (once send_after has passed, or on "Send now") tracks delivery
 * separately.
 *
 * It requires the cancel window to have passed (or an explicit Send now) and
 * a complete sender snapshot.
 *
 * The official PDF is written after the financial transaction commits, never
 * inside it: a file cannot be rolled back with the database. If writing it
 * fails, the invoice is still correctly issued and OfficialInvoicePdf writes it
 * on the next attempt (another issue call, or the first download).
 *
 * Exactly once, under retries and races:
 * - the number is allocated and the status changed in one transaction, with a
 *   conditional UPDATE (only from Approved); the loser rolls back, including
 *   its number, so numbers are never doubled or skipped;
 * - issuing an issued invoice returns it unchanged with its existing number;
 * - UNIQUE(workspace_id, number) is the final guarantee; on a clash the whole
 *   issue is retried.
 */
final class IssueInvoiceAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly InvoiceNumberAllocator $numbers,
        private readonly OfficialInvoicePdf $pdf,
    ) {}

    /**
     * @param  bool  $sendNow  the owner explicitly chose to skip the rest of the cancel window
     */
    public function handle(Invoice $invoice, ?User $actor, bool $sendNow = false): Invoice
    {
        try {
            $issued = retry(3, fn () => DB::transaction(fn () => $this->issue($invoice, $actor, $sendNow)), 0, fn ($exception) => $exception instanceof UniqueConstraintViolationException);
        } catch (InvoiceException $exception) {
            $issued = $invoice->fresh();

            if (! $issued?->isIssued()) {
                throw $exception;
            }
        }

        $this->pdf->ensureSafely($issued);

        return $issued->refresh();
    }

    private function issue(Invoice $invoice, ?User $actor, bool $sendNow): Invoice
    {
        $invoice = Invoice::query()->with('workspace')->findOrFail($invoice->getKey());

        if ($invoice->isIssued()) {
            return $invoice;
        }

        if (! $invoice->status->canTransitionTo(InvoiceStatus::Issued)) {
            throw InvoiceException::invalidTransition($invoice->status, InvoiceStatus::Issued);
        }

        if (! $sendNow && $invoice->send_after?->isFuture()) {
            throw InvoiceException::sendNotDue();
        }

        if (! SenderSnapshot::isComplete($invoice->bill_from)) {
            throw InvoiceException::senderIncomplete('issuing');
        }

        $today = CarbonImmutable::now($invoice->workspace->timezone)->startOfDay();
        $number = $this->numbers->next($invoice->workspace, $today->year);

        $claimed = Invoice::query()
            ->whereKey($invoice->getKey())
            ->where('status', InvoiceStatus::Approved->value)
            ->whereNull('number')
            ->update([
                'status' => InvoiceStatus::Issued->value,
                'number' => $number,
                'version' => DB::raw('version + 1'),
                'issued_at' => now(),
                'issue_date' => $today->toDateString(),
                'due_date' => $today->addDays($invoice->due_in_days)->toDateString(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw InvoiceException::invalidTransition($invoice->status, InvoiceStatus::Issued);
        }

        $invoice->refresh();

        $this->auditLogger->handle(
            $invoice->workspace,
            null,
            $actor,
            AuditAction::INVOICE_ISSUED,
            "Invoice {$number} \"{$invoice->title}\" for {$invoice->bill_to['name']} was issued.",
            $invoice,
            ['number' => $number, 'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency->value],
        );

        return $invoice;
    }
}
