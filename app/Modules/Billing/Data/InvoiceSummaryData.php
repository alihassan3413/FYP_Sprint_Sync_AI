<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Models\Invoice;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the invoice list. Public ids only; dates are calendar dates.
 */
#[TypeScript]
final class InvoiceSummaryData extends Data
{
    public function __construct(
        public string $public_id,
        public ?string $number,
        public string $title,
        public string $client_name,
        #[LiteralTypeScriptType("'needs_hours' | 'ready_to_review' | 'approved' | 'issued'")]
        public string $status,
        public string $status_label,
        #[LiteralTypeScriptType("'fixed' | 'hourly'")]
        public string $pricing_mode,
        #[LiteralTypeScriptType("'review_before_sending' | 'auto_send'")]
        public string $delivery_mode,
        public string $currency,
        public string $period_start,
        public string $period_end,
        public string $planned_send_on,
        public int $total_minor,
        public int $lines_missing_hours,
    ) {}

    public static function fromModel(Invoice $invoice): self
    {
        return new self(
            public_id: $invoice->public_id,
            number: $invoice->number,
            title: $invoice->title,
            client_name: $invoice->bill_to['name'],
            status: $invoice->status->value,
            status_label: $invoice->status->label(),
            pricing_mode: $invoice->pricing_mode->value,
            delivery_mode: $invoice->delivery_mode->value,
            currency: $invoice->currency->value,
            period_start: $invoice->period_start->toDateString(),
            period_end: $invoice->period_end->toDateString(),
            planned_send_on: $invoice->planned_send_on->toDateString(),
            total_minor: $invoice->total_minor,
            lines_missing_hours: (int) ($invoice->lines_missing_hours ?? $invoice->lines->whereNull('quantity_centi')->count()),
        );
    }
}
