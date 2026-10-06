<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceRecalculator;
use App\Modules\Billing\Support\TrackedHours;
use Illuminate\Support\Facades\DB;

/**
 * "Refresh recorded hours": reads the period's billable time again (time
 * added after the draft was prepared, for example Oct 31 entered on Nov 2).
 * Only on the owner's say-so and only while the invoice can still be edited;
 * approved and issued invoices never change.
 *
 * Recorded hours are updated on every line. Billed hours follow only where
 * the owner hadn't changed them (they still equalled the old recorded hours,
 * or were blank); hours the owner set on purpose, with their reason, stay.
 */
final class RefreshInvoiceTrackedHoursAction
{
    public function __construct(
        private readonly TrackedHours $trackedHours,
        private readonly InvoiceRecalculator $recalculator,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Invoice $invoice, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->status->isEditable()) {
                throw InvoiceException::locked($invoice->status);
            }

            if (! TrackedHours::applies($invoice)) {
                return $invoice;
            }

            $changes = [];

            foreach ($invoice->lines as $line) {
                if ($line->person_id === null) {
                    continue;
                }

                $seconds = $this->trackedHours->secondsFor($invoice, $line->person_id);
                $recorded = TrackedHours::centi($seconds);

                if ($line->tracked_seconds === $seconds) {
                    continue;
                }

                $followed = $line->quantity_centi === null || $line->quantity_centi === $line->tracked_quantity_centi;
                $changes[] = ['line' => $line->description, 'from_seconds' => $line->tracked_seconds, 'to_seconds' => $seconds, 'billed_followed' => $followed];

                $line->tracked_seconds = $seconds;
                $line->tracked_quantity_centi = $recorded;

                if ($followed) {
                    $line->quantity_centi = $seconds > 0 ? $recorded : null;
                    $line->quantity_reason = null;
                }
            }

            if ($changes === []) {
                $invoice->forceFill(['tracked_at' => now()])->save();

                return $invoice;
            }

            $complete = $this->recalculator->recalculate($invoice);
            $next = $complete ? InvoiceStatus::ReadyToReview : InvoiceStatus::NeedsHours;

            $invoice->forceFill(['status' => $next, 'version' => $invoice->version + 1, 'tracked_at' => now()])->save();

            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_HOURS_REFRESHED,
                "{$actor->name} refreshed recorded hours on \"{$invoice->title}\" for {$invoice->bill_to['name']}.",
                $invoice,
                ['lines' => $changes],
            );

            return $invoice;
        });
    }
}
