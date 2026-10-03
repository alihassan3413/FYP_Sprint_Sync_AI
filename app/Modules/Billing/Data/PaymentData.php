<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Models\Payment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A payment as the invoice page shows it. Addressed by public id only.
 */
#[TypeScript]
final class PaymentData extends Data
{
    public function __construct(
        public string $public_id,
        public int $amount_minor,
        public string $received_on,
        public string $method,
        public string $method_label,
        public ?string $reference,
        public ?string $note,
        public ?string $recorded_by,
        public ?string $voided_at,
        public ?string $voided_by,
        public ?string $void_reason,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            public_id: $payment->public_id,
            amount_minor: $payment->amount_minor,
            received_on: $payment->received_on->toDateString(),
            method: $payment->method->value,
            method_label: $payment->method->label(),
            reference: $payment->reference,
            note: $payment->note,
            recorded_by: $payment->recorder?->name,
            voided_at: $payment->voided_at?->toIso8601String(),
            voided_by: $payment->voider?->name,
            void_reason: $payment->void_reason,
        );
    }
}
