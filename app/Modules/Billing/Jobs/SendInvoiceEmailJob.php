<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Actions\SendInvoiceDeliveryAction;
use App\Modules\Billing\Models\InvoiceDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one invoice email. The payload is only the delivery's id: SMTP
 * settings, credentials and the PDF are loaded when it runs.
 *
 * Never retried by the queue (tries = 1): a failure is recorded on the
 * delivery and retried by a person, so an email that may have gone out is
 * never sent again automatically. ShouldBeUnique keeps duplicate dispatches
 * out of the queue; the delivery's conditional claim is the real guarantee.
 */
final class SendInvoiceEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(SendInvoiceDeliveryAction $send): void
    {
        $delivery = InvoiceDelivery::query()->find($this->deliveryId);

        if ($delivery !== null) {
            $send->handle($delivery);
        }
    }
}
