<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Jobs\SendInvoiceEmailJob;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;

/**
 * "Send now": the same pipeline as the scheduled dispatcher, skipping the rest
 * of the cancel window. Issue + delivery record happen here; the email itself
 * is sent by the queued job. Repeating it finds the same issued invoice and
 * delivery, and the job's claim makes sure only one send happens.
 */
final class SendInvoiceNowAction
{
    public function __construct(private readonly PrepareInvoiceDeliveryAction $prepare) {}

    public function handle(Invoice $invoice, User $actor): InvoiceDelivery
    {
        $delivery = $this->prepare->handle($invoice, $actor, sendNow: true);

        if ($delivery->status === DeliveryStatus::Pending) {
            SendInvoiceEmailJob::dispatch($delivery->id);
        }

        return $delivery;
    }
}
