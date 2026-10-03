<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceRecalculator;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a draft invoice before approval, for this invoice only:
 * - hourly invoices: the hours per line (null = not entered, 0 = nothing billed)
 * - fixed invoices: the amount per line
 * Rates on hourly invoices are part of the snapshot and are not editable here.
 *
 * Values are absolute, so saving the same values again changes nothing. When
 * the last missing hours arrive the invoice becomes Ready to review; clearing
 * one sends it back to Needs hours. Each real change bumps `version`, so an
 * approval based on an older view of the invoice is refused.
 *
 * Autosave can call this often, so "edited" is audited at most once per person
 * per invoice every 10 minutes; becoming ready is always audited.
 */
final class UpdateInvoiceLinesAction
{
    private const AUDIT_COALESCE_MINUTES = 10;

    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly InvoiceRecalculator $recalculator,
    ) {}

    /**
     * @param  array<int, int|null>  $values  by line position: hundredths of an hour (hourly) or minor units (fixed)
     */
    public function handle(Invoice $invoice, array $values, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $values, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->status->isEditable()) {
                throw InvoiceException::locked($invoice->status);
            }

            $hourly = $invoice->pricing_mode === PricingMode::Hourly;
            $column = $hourly ? 'quantity_centi' : 'unit_price_minor';
            $changed = [];

            foreach ($invoice->lines as $line) {
                if (! array_key_exists($line->position, $values) || $line->{$column} === $values[$line->position]) {
                    continue;
                }

                $line->{$column} = $values[$line->position];
                $changed[] = $line->description;
            }

            if ($changed === []) {
                return $invoice;
            }

            $complete = $this->recalculator->recalculate($invoice);
            $next = $hourly ? ($complete ? InvoiceStatus::ReadyToReview : InvoiceStatus::NeedsHours) : $invoice->status;
            $before = $invoice->status;

            if ($next !== $before && ! $before->canTransitionTo($next)) {
                throw InvoiceException::invalidTransition($before, $next);
            }

            $invoice->forceFill(['status' => $next, 'version' => $invoice->version + 1])->save();

            $this->audit($invoice, $actor, $changed, $before, $hourly);

            return $invoice;
        });
    }

    /**
     * @param  list<string>  $changed
     */
    private function audit(Invoice $invoice, User $actor, array $changed, InvoiceStatus $before, bool $hourly): void
    {
        $label = "\"{$invoice->title}\" for {$invoice->bill_to['name']}";

        $recentlyAudited = AuditLog::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->where('user_id', $actor->id)
            ->where('action', AuditAction::INVOICE_UPDATED->value)
            ->where('created_at', '>=', now()->subMinutes(self::AUDIT_COALESCE_MINUTES))
            ->exists();

        if (! $recentlyAudited) {
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_UPDATED,
                $hourly ? "{$actor->name} entered hours on {$label}." : "{$actor->name} changed amounts on {$label}.",
                $invoice,
                ['lines' => $changed, 'status' => $invoice->status->value],
            );
        }

        if ($before !== InvoiceStatus::ReadyToReview && $invoice->status === InvoiceStatus::ReadyToReview) {
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_READY,
                "{$label} has all its hours and is ready to review.",
                $invoice,
                ['total_minor' => $invoice->total_minor, 'currency' => $invoice->currency->value],
            );
        }
    }
}
