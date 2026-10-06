<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\MoneyFormatter;

/**
 * Voids a payment recorded by mistake. The payment stays in history, marked
 * voided with who, when and why, and no longer counts as paid. A conditional
 * update (only while not voided) makes a double click or retry harmless: one
 * void, one audit entry.
 */
final class VoidPaymentAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Payment $payment, string $reason, User $actor): Payment
    {
        $voided = Payment::query()
            ->whereKey($payment->getKey())
            ->whereNull('voided_at')
            ->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
                'updated_at' => now(),
            ]);

        $payment->refresh();

        if ($voided === 1) {
            $invoice = $payment->invoice()->with('workspace')->firstOrFail();

            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::PAYMENT_VOIDED,
                "{$actor->name} voided a payment of ".MoneyFormatter::format($payment->amount_minor, $payment->currency)." on invoice {$invoice->number}.",
                $payment,
                [
                    'invoice' => $invoice->number,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency->value,
                    'received_on' => $payment->received_on->toDateString(),
                    'reason' => $reason,
                ],
            );
        }

        return $payment;
    }
}
