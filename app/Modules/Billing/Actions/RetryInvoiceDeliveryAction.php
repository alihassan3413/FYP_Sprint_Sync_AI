<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Exceptions\DeliveryException;
use App\Modules\Billing\Jobs\SendInvoiceEmailJob;
use App\Modules\Billing\Models\InvoiceDelivery;

/**
 * "Retry sending": puts a failed delivery back to pending and queues it, for
 * the same issued invoice, number, PDF and recipients. A delivery whose outcome
 * is uncertain (the client may already have it) needs the owner to confirm.
 * A conditional update makes a repeated click a no-op.
 */
final class RetryInvoiceDeliveryAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(InvoiceDelivery $delivery, bool $confirmedPossibleDuplicate, User $actor): InvoiceDelivery
    {
        $from = [DeliveryStatus::Failed->value];

        if ($confirmedPossibleDuplicate) {
            $from[] = DeliveryStatus::NeedsAttention->value;
        } elseif ($delivery->status === DeliveryStatus::NeedsAttention) {
            throw DeliveryException::needsConfirmation();
        }

        $reset = InvoiceDelivery::query()
            ->whereKey($delivery->getKey())
            ->whereIn('status', $from)
            ->update(['status' => DeliveryStatus::Pending->value, 'error' => null, 'updated_at' => now()]);

        $delivery->refresh();

        if ($reset === 1) {
            $invoice = $delivery->invoice()->with('workspace')->firstOrFail();

            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                $actor,
                AuditAction::INVOICE_DELIVERY_RETRIED,
                "{$actor->name} retried emailing invoice {$invoice->number}.",
                $delivery,
                ['invoice' => $invoice->number, 'confirmed_possible_duplicate' => $confirmedPossibleDuplicate],
            );

            SendInvoiceEmailJob::dispatch($delivery->id);
        } elseif (! in_array($delivery->status, [DeliveryStatus::Pending, DeliveryStatus::Sending], true)) {
            throw DeliveryException::notRetryable();
        }

        return $delivery;
    }
}
