<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceSource;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceRecalculator;
use App\Modules\Billing\Support\SenderSnapshot;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class SaveManualInvoiceAction
{
    public function __construct(
        private readonly InvoiceRecalculator $recalculator,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function create(Workspace $workspace, array $details, string $idempotencyKey, User $actor): Invoice
    {
        $existing = fn () => Invoice::query()->where('workspace_id', $workspace->id)->where('idempotency_key', $idempotencyKey)->first();

        if ($found = $existing()) {
            return $found;
        }

        try {
            $invoice = DB::transaction(function () use ($workspace, $details, $idempotencyKey) {
                $invoice = new Invoice;
                $invoice->forceFill([
                    'workspace_id' => $workspace->id,
                    'billing_plan_id' => null,
                    'source' => InvoiceSource::Manual,
                    'status' => InvoiceStatus::ReadyToReview,
                    'version' => 1,
                    'pricing_mode' => PricingMode::Fixed,
                    'delivery_mode' => DeliveryMode::ReviewBeforeSending,
                    'generated_on' => CarbonImmutable::now($workspace->timezone)->toDateString(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                return $this->fill($invoice, $workspace, $details);
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::INVOICE_CREATED,
            "{$actor->name} created a draft invoice \"{$invoice->title}\" for {$invoice->bill_to['name']}.",
            $invoice,
            ['source' => InvoiceSource::Manual->value, 'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency->value],
        );

        return $invoice;
    }

    public function update(Invoice $invoice, array $details, int $reviewedVersion, User $actor): Invoice
    {
        $updated = DB::transaction(function () use ($invoice, $details, $reviewedVersion) {
            $locked = Invoice::query()->with('workspace')->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $locked->isManual()) {
                throw InvoiceException::notManual();
            }

            if ($locked->isVoided()) {
                throw InvoiceException::voided();
            }

            if ($locked->status !== InvoiceStatus::ReadyToReview) {
                throw InvoiceException::locked($locked->status);
            }

            if ($locked->version !== $reviewedVersion) {
                throw InvoiceException::changedDuringReview();
            }

            $locked->lines()->delete();
            $locked->adjustments()->delete();
            $locked->forceFill(['version' => $locked->version + 1]);

            return $this->fill($locked, $locked->workspace, $details);
        });

        $this->auditLogger->handle(
            $updated->workspace,
            null,
            $actor,
            AuditAction::INVOICE_UPDATED,
            "{$actor->name} edited the draft invoice \"{$updated->title}\".",
            $updated,
            ['total_minor' => $updated->total_minor, 'currency' => $updated->currency->value],
        );

        return $updated;
    }

    private function fill(Invoice $invoice, Workspace $workspace, array $details): Invoice
    {
        $client = $details['client_id'] === null ? null : Client::query()->where('workspace_id', $workspace->id)->findOrFail($details['client_id']);
        $issueDate = CarbonImmutable::parse($details['issue_date']);
        $dueDate = CarbonImmutable::parse($details['due_date']);

        $invoice->forceFill([
            'client_id' => $client?->id,
            'title' => $details['title'],
            'currency' => $details['currency'],
            'bill_to' => $client?->billingSnapshot() ?? $details['bill_to'],
            'bill_from' => SenderSnapshot::from($workspace->invoicingProfile),
            'notes' => $details['notes'],
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'period_start' => $issueDate->toDateString(),
            'period_end' => $issueDate->toDateString(),
            'planned_send_on' => $issueDate->toDateString(),
            'due_in_days' => (int) $issueDate->diffInDays($dueDate),
        ])->save();

        $invoice->lines()->createMany(array_map(fn (array $line, int $index) => [
            'position' => $index + 1,
            'description' => $line['description'],
            'quantity_centi' => $line['quantity_centi'],
            'unit_price_minor' => $line['unit_price_minor'],
        ], $details['lines'], array_keys($details['lines'])));

        $invoice->adjustments()->createMany(array_map(fn (array $adjustment, int $index) => [
            'position' => $index + 1,
            'kind' => $adjustment['kind'],
            'type' => $adjustment['type'],
            'label' => $adjustment['label'] ?: ucfirst($adjustment['kind'] instanceof AdjustmentKind ? $adjustment['kind']->value : (string) $adjustment['kind']),
            'value' => $adjustment['value'],
        ], $details['adjustments'], array_keys($details['adjustments'])));

        $invoice->unsetRelation('lines')->unsetRelation('adjustments');
        $this->recalculator->recalculate($invoice);
        $invoice->save();

        return $invoice->refresh();
    }
}
