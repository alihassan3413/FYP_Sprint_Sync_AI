<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\BillingCycle;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceRecalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Turns one month of a recurring invoice into a real invoice snapshot.
 *
 * Idempotent: UNIQUE(billing_plan_id, period_start) means one invoice per plan
 * per month. A second call, a retry or a concurrent run finds and returns the
 * invoice that already exists instead of creating another.
 *
 * Everything the invoice shows is copied: lines (name, job title, rate or
 * amount), adjustments, who it is billed to and the schedule dates. From here
 * on it never reads the plan, the client or the team to describe itself.
 * Fixed invoices start Ready to review; hourly ones start Needs hours.
 */
final class GenerateInvoiceFromPlanAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly InvoiceRecalculator $recalculator,
    ) {}

    public function handle(BillingPlan $plan, BillingCycle $cycle, ?User $actor): Invoice
    {
        if ($existing = $this->existing($plan, $cycle)) {
            return $existing;
        }

        $this->guard($plan);

        try {
            return DB::transaction(fn () => $this->create($plan, $cycle, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($plan, $cycle) ?? throw $exception;
        }
    }

    private function existing(BillingPlan $plan, BillingCycle $cycle): ?Invoice
    {
        return Invoice::query()
            ->where('billing_plan_id', $plan->id)
            ->whereDate('period_start', $cycle->periodStart->toDateString())
            ->first();
    }

    private function guard(BillingPlan $plan): void
    {
        $client = $plan->client;

        if ($client->workspace_id !== $plan->workspace_id) {
            throw new LogicException('A recurring invoice and its client must belong to the same workspace.');
        }

        if ($plan->isPaused()) {
            throw InvoiceException::cannotGenerate("\"{$plan->name}\" is paused. Resume it to prepare invoices.");
        }

        if ($client->isArchived()) {
            throw InvoiceException::cannotGenerate("{$client->name} is archived. Restore the client to prepare invoices.");
        }

        if ($plan->lines()->doesntExist()) {
            throw InvoiceException::cannotGenerate("\"{$plan->name}\" has nothing to bill yet.");
        }
    }

    private function create(BillingPlan $plan, BillingCycle $cycle, ?User $actor): Invoice
    {
        $client = $plan->client;
        $hourly = $plan->pricing_mode === PricingMode::Hourly;

        $invoice = new Invoice;
        $invoice->forceFill([
            'workspace_id' => $plan->workspace_id,
            'client_id' => $client->id,
            'billing_plan_id' => $plan->id,
            'status' => $hourly ? InvoiceStatus::NeedsHours : InvoiceStatus::ReadyToReview,
            'version' => 1,
            'title' => $plan->name,
            'pricing_mode' => $plan->pricing_mode,
            'delivery_mode' => $plan->delivery_mode,
            'currency' => $plan->currency,
            'period_start' => $cycle->periodStart,
            'period_end' => $cycle->periodEnd,
            'generated_on' => $cycle->generationDate,
            'planned_send_on' => $cycle->sendDate,
            'due_in_days' => $plan->due_in_days,
            'bill_to' => [
                'name' => $client->name,
                'billing_email' => $client->billing_email,
                'cc_emails' => $client->cc_emails ?? [],
                'address' => $client->address,
                'tax_id' => $client->tax_id,
            ],
        ])->save();

        $invoice->lines()->createMany($plan->lines->map(fn ($line) => [
            'position' => $line->position,
            'person_id' => $line->person_id,
            'description' => $line->description,
            'role_label' => $line->role_label,
            'quantity_centi' => $hourly ? null : 100,
            'unit_price_minor' => $line->unit_price_minor,
        ])->all());

        $invoice->adjustments()->createMany($plan->adjustments->map(fn ($adjustment) => [
            'position' => $adjustment->position,
            'kind' => $adjustment->kind,
            'type' => $adjustment->type,
            'label' => $adjustment->label,
            'value' => $adjustment->value,
        ])->all());

        $this->recalculator->recalculate($invoice);
        $invoice->save();

        $month = $cycle->periodStart->format('F Y');
        $this->auditLogger->handle(
            $plan->workspace,
            null,
            $actor,
            AuditAction::INVOICE_GENERATED,
            ($actor?->name ?? 'SprintSync')." prepared the {$month} invoice \"{$plan->name}\" for {$client->name}.",
            $invoice,
            [
                'client' => $client->name,
                'plan' => $plan->name,
                'period_start' => $cycle->periodStart->toDateString(),
                'status' => $invoice->status->value,
                'total_minor' => $hourly ? null : $invoice->total_minor,
                'currency' => $invoice->currency->value,
            ],
        );

        return $invoice;
    }
}
