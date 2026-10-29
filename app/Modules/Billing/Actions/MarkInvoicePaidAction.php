<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\PaymentMethod;
use App\Modules\Billing\Exceptions\PaymentException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use Carbon\CarbonImmutable;

final class MarkInvoicePaidAction
{
    public function __construct(
        private readonly RecordPaymentAction $payments,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Invoice $invoice, string $idempotencyKey, CarbonImmutable $receivedOn, PaymentMethod $method, ?string $reference, User $actor): Payment
    {
        if (! $invoice->isIssued()) {
            throw PaymentException::invoiceNotIssued();
        }

        $existing = Payment::query()->where('workspace_id', $invoice->workspace_id)->where('idempotency_key', $idempotencyKey)->first();
        $balance = $invoice->balanceDueMinor();

        $payment = $this->payments->handle($invoice, $idempotencyKey, $existing?->amount_minor ?? $balance, $receivedOn, $method, $reference, 'Marked as paid', $actor);

        if ($existing === null) {
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_MARKED_PAID,
                "{$actor->name} marked invoice {$invoice->number} as paid.",
                $invoice,
                ['amount_minor' => $payment->amount_minor, 'currency' => $invoice->currency->value],
            );
        }

        return $payment;
    }
}
