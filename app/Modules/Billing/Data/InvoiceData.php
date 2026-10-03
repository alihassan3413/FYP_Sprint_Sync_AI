<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\SenderSnapshot;
use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything the invoice page shows, read only from the invoice's own
 * snapshot. Team members are not referenced at all here: the line's own
 * description and job title are what was billed.
 */
#[TypeScript]
final class InvoiceData extends Data
{
    /**
     * @param  list<array{position: int, description: string, role_label: string|null, quantity_centi: int|null, unit_price_minor: int, amount_minor: int|null}>  $lines
     * @param  list<array{kind: string, type: string, label: string, value: int, amount_minor: int}>  $adjustments
     * @param  array{name: string, billing_email: string, cc_emails: list<string>, address: string|null, tax_id: string|null}  $bill_to
     */
    public function __construct(
        public InvoiceSummaryData $summary,
        public int $version,
        public bool $is_editable,
        #[LiteralTypeScriptType('{ name: string; billing_email: string; cc_emails: string[]; address: string | null; tax_id: string | null }')]
        public array $bill_to,
        #[LiteralTypeScriptType('{ position: number; description: string; role_label: string | null; quantity_centi: number | null; unit_price_minor: number; amount_minor: number | null }[]')]
        public array $lines,
        #[LiteralTypeScriptType("{ kind: 'fee' | 'tax' | 'discount'; type: 'percentage' | 'fixed'; label: string; value: number; amount_minor: number }[]")]
        public array $adjustments,
        public int $subtotal_minor,
        public string $generated_on,
        public int $due_in_days,
        public ?string $issue_date,
        public ?string $due_date,
        public ?string $approved_at,
        public ?string $approved_by,
        public ?string $send_after,
        public ?string $issued_at,
        public ?string $client_public_id,
        #[LiteralTypeScriptType('{ business_name: string; legal_name: string | null; billing_email: string; phone: string | null; address_line1: string; address_line2: string | null; city: string; region: string | null; postal_code: string | null; country: string; tax_id: string | null } | null')]
        public ?array $bill_from,
        public bool $sender_complete,
        public ?string $logo_url,
        public string $pdf_url,
        public string $pdf_filename,
    ) {}

    public static function fromModel(Invoice $invoice): self
    {
        return new self(
            summary: InvoiceSummaryData::fromModel($invoice),
            version: $invoice->version,
            is_editable: $invoice->status->isEditable(),
            bill_to: $invoice->bill_to,
            lines: $invoice->lines->map(fn ($line) => $line->only(['position', 'description', 'role_label', 'quantity_centi', 'unit_price_minor', 'amount_minor']))->values()->all(),
            adjustments: $invoice->adjustments->map(fn ($adjustment) => [
                'kind' => $adjustment->kind->value,
                'type' => $adjustment->type->value,
                'label' => $adjustment->label,
                'value' => $adjustment->value,
                'amount_minor' => $adjustment->amount_minor,
            ])->values()->all(),
            subtotal_minor: $invoice->subtotal_minor,
            generated_on: $invoice->generated_on->toDateString(),
            due_in_days: $invoice->due_in_days,
            issue_date: $invoice->issue_date?->toDateString(),
            due_date: $invoice->due_date?->toDateString(),
            approved_at: $invoice->approved_at?->toIso8601String(),
            approved_by: $invoice->approver?->name,
            send_after: $invoice->send_after?->toIso8601String(),
            issued_at: $invoice->issued_at?->toIso8601String(),
            client_public_id: $invoice->client?->public_id,
            /* The logo's storage path stays on the server; the page gets an authorised URL instead. */
            bill_from: $invoice->bill_from === null ? null : Arr::except($invoice->bill_from, 'logo_path'),
            sender_complete: SenderSnapshot::isComplete($invoice->bill_from),
            logo_url: ($invoice->bill_from['logo_path'] ?? null) === null ? null : route('workspace.invoices.logo', [
                'workspace' => $invoice->workspace->slug,
                'invoice' => $invoice->public_id,
            ]),
            pdf_url: route('workspace.invoices.pdf', ['workspace' => $invoice->workspace->slug, 'invoice' => $invoice->public_id]),
            pdf_filename: app(InvoicePdfRenderer::class)->filename($invoice),
        );
    }
}
