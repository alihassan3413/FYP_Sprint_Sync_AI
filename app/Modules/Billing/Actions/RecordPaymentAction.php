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
use App\Modules\Billing\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records money received against an issued invoice.
 *
 * - Exactly once: the form's idempotency key is unique per workspace, so a
 *   double click, retry or replay returns the payment already recorded.
 * - Never more than owed: inside one transaction the payment is inserted
 *   first (taking the database write lock), then the active total is summed
 *   including it; if that exceeds the invoice total, nothing is kept. Two
 *   simultaneous payments can therefore never overpay together.
 * - The currency is always the invoice's; there is no FX.
 * - The invoice itself (totals, lines, snapshot, PDF) is never touched.
 */
final class RecordPaymentAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(
        Invoice $invoice,
        string $idempotencyKey,
        int $amountMinor,
        CarbonImmutable $receivedOn,
        PaymentMethod $method,
        ?string $reference,
        ?string $note,
        User $actor,
    ): Payment {
        if ($existing = $this->existing($invoice, $idempotencyKey)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($invoice, $idempotencyKey, $amountMinor, $receivedOn, $method, $reference, $note, $actor) {
                $invoice = Invoice::query()->with('workspace')->lockForUpdate()->findOrFail($invoice->getKey());

                if (! $invoice->isIssued()) {
                    throw PaymentException::invoiceNotIssued();
                }

                $payment = $invoice->payments()->forceCreate([
                    'workspace_id' => $invoice->workspace_id,
                    'idempotency_key' => $idempotencyKey,
                    'amount_minor' => $amountMinor,
                    'currency' => $invoice->currency,
                    'received_on' => $receivedOn->toDateString(),
                    'method' => $method,
                    'reference' => $reference,
                    'note' => $note,
                    'recorded_by' => $actor->id,
                ]);

                $paid = (int) $invoice->payments()->active()->sum('amount_minor');

                if ($paid > $invoice->total_minor) {
                    throw PaymentException::exceedsBalance($invoice->total_minor - ($paid - $amountMinor), $invoice->currency);
                }

                $this->auditLogger->handle(
                    $invoice->workspace,
                    null,
                    $actor,
                    AuditAction::PAYMENT_RECORDED,
                    "{$actor->name} recorded a payment of ".MoneyFormatter::format($amountMinor, $invoice->currency)." for invoice {$invoice->number}.",
                    $payment,
                    [
                        'invoice' => $invoice->number,
                        'amount_minor' => $amountMinor,
                        'currency' => $invoice->currency->value,
                        'received_on' => $receivedOn->toDateString(),
                        'method' => $method->value,
                        'reference' => $reference,
                    ],
                );

                return $payment;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($invoice, $idempotencyKey) ?? throw $exception;
        }
    }

    private function existing(Invoice $invoice, string $idempotencyKey): ?Payment
    {
        $payment = Payment::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($payment !== null && $payment->invoice_id !== $invoice->id) {
            throw PaymentException::keyReused();
        }

        return $payment;
    }
}
