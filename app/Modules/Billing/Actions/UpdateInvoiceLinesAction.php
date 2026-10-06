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
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\InvoiceRecalculator;
use App\Modules\Billing\Support\MoneyFormatter;
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
 * Hourly lines with recorded time (tracked_quantity_centi) keep it untouched:
 * the value here is what the client is billed. Billing different hours than
 * were recorded needs a reason, and who changed it and when is kept on the
 * line and audited (every override, never coalesced). Lines without recorded
 * time (no time tracking, external contractors) take hours by hand, with an
 * optional reason.
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
     * @param  array<int, string|null>  $reasons  by line position: why billed hours differ from recorded (hourly)
     */
    public function handle(Invoice $invoice, array $values, User $actor, array $reasons = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $values, $actor, $reasons) {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $invoice->status->isEditable()) {
                throw InvoiceException::locked($invoice->status);
            }

            $hourly = $invoice->pricing_mode === PricingMode::Hourly;
            $column = $hourly ? 'quantity_centi' : 'unit_price_minor';
            $changed = [];
            $overrides = [];

            foreach ($invoice->lines as $line) {
                $valueChanged = array_key_exists($line->position, $values) && $line->{$column} !== $values[$line->position];

                if ($valueChanged) {
                    $line->{$column} = $values[$line->position];
                    $changed[] = $line->description;
                }

                if ($hourly && ($valueChanged || array_key_exists($line->position, $reasons))) {
                    if ($this->applyReason($line, trim((string) ($reasons[$line->position] ?? '')) ?: null, $valueChanged, $actor)) {
                        $overrides[] = $line;
                    }

                    if ($line->isDirty('quantity_reason') && ! $valueChanged) {
                        $changed[] = $line->description;
                    }
                }
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
            $this->auditOverrides($invoice, $actor, $overrides);

            return $invoice;
        });
    }

    /**
     * Keeps the billed-hours reason in step with the hours. Returns whether
     * this is an override worth auditing (billed differs from recorded).
     */
    private function applyReason(InvoiceLine $line, ?string $reason, bool $valueChanged, User $actor): bool
    {
        $recorded = $line->tracked_quantity_centi;
        $differs = $recorded !== null && $line->quantity_centi !== null && $line->quantity_centi !== $recorded;

        if ($differs && $reason === null && ($valueChanged || $line->quantity_reason === null)) {
            throw InvoiceException::billedHoursNeedReason($line->description);
        }

        $line->quantity_reason = $differs || $recorded === null ? ($reason ?? ($valueChanged ? null : $line->quantity_reason)) : null;

        if ($valueChanged || $line->isDirty('quantity_reason')) {
            $line->quantity_set_by = $actor->id;
            $line->quantity_set_at = now();
        }

        return $differs && ($valueChanged || $line->isDirty('quantity_reason'));
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function auditOverrides(Invoice $invoice, User $actor, array $lines): void
    {
        foreach ($lines as $line) {
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_HOURS_OVERRIDDEN,
                sprintf('%s billed %s h for %s on "%s" (recorded %s h): %s', $actor->name, MoneyFormatter::quantity((int) $line->quantity_centi), $line->description, $invoice->title, MoneyFormatter::quantity((int) $line->tracked_quantity_centi), $line->quantity_reason),
                $invoice,
                [
                    'line' => $line->description,
                    'recorded_centi' => $line->tracked_quantity_centi,
                    'billed_centi' => $line->quantity_centi,
                    'difference_centi' => (int) $line->quantity_centi - (int) $line->tracked_quantity_centi,
                    'reason' => $line->quantity_reason,
                ],
            );
        }
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
