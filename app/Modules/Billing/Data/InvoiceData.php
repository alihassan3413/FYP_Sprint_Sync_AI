<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Audit\Data\SystemActor;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceAttention;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\SenderSnapshot;
use App\Modules\Billing\Support\TrackedHours;
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
     * @param  list<array{position: int, description: string, role_label: string|null, quantity_centi: int|null, unit_price_minor: int, amount_minor: int|null, tracked_seconds: int|null, tracked_quantity_centi: int|null, quantity_reason: string|null}>  $lines
     * @param  list<array{kind: string, type: string, label: string, value: int, amount_minor: int}>  $adjustments
     * @param  array{name: string, billing_email: string, cc_emails: list<string>, address: string|null, tax_id: string|null}  $bill_to
     */
    public function __construct(
        public InvoiceSummaryData $summary,
        public int $version,
        public bool $is_editable,
        #[LiteralTypeScriptType('{ name: string; billing_email: string; cc_emails: string[]; address: string | null; tax_id: string | null }')]
        public array $bill_to,
        #[LiteralTypeScriptType('{ position: number; description: string; role_label: string | null; quantity_centi: number | null; unit_price_minor: number; amount_minor: number | null; tracked_seconds: number | null; tracked_quantity_centi: number | null; quantity_reason: string | null }[]')]
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
        /** Newest first; voided ones included so the history is complete. */
        #[LiteralTypeScriptType('PaymentData[]')]
        public array $payments,
        /** The email delivery. The error is already a short, safe sentence. */
        #[LiteralTypeScriptType("{ status: 'pending' | 'sending' | 'sent' | 'failed' | 'needs_attention'; status_label: string; recipient: string; cc: string[]; recipients: string[]; bcc: string[]; message: string | null; attempts: number; last_attempted_at: string | null; sent_at: string | null; error: string | null } | null")]
        public ?array $delivery,
        public bool $approved_automatically,
        /** Hours come from time tracking (recorded) and can be overridden (billed). */
        public bool $uses_time_tracking,
        public ?string $tracked_at,
        /** Will be checked and sent automatically on its planned send date. */
        public bool $sends_automatically,
        /** Why automatic sending is waiting for the owner, while it is. */
        #[LiteralTypeScriptType('{ code: AutomationReason; message: string }[]')]
        public array $automation_reasons,
        public ?string $notes = null,
        public ?string $voided_at = null,
        public ?string $void_reason = null,
        public ?string $currency_code = null,
    ) {}

    public static function fromModel(Invoice $invoice): self
    {
        return new self(
            summary: InvoiceSummaryData::fromModel($invoice),
            version: $invoice->version,
            is_editable: $invoice->status->isEditable(),
            bill_to: $invoice->bill_to,
            lines: $invoice->lines->map(fn ($line) => $line->only(['position', 'description', 'role_label', 'quantity_centi', 'unit_price_minor', 'amount_minor', 'tracked_seconds', 'tracked_quantity_centi', 'quantity_reason']))->values()->all(),
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
            approved_by: $invoice->approved_automatically ? SystemActor::NAME : $invoice->approver?->name,
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
            payments: $invoice->payments()
                ->with(['recorder:id,name', 'voider:id,name'])
                ->orderByDesc('received_on')
                ->orderByDesc('id')
                ->get()
                ->map(PaymentData::fromModel(...))
                ->all(),
            delivery: $invoice->delivery === null ? null : [
                'status' => $invoice->delivery->status->value,
                'status_label' => $invoice->delivery->status->label(),
                'recipient' => $invoice->delivery->recipient,
                'cc' => $invoice->delivery->cc ?? [],
                'recipients' => $invoice->delivery->recipients ?? [$invoice->delivery->recipient],
                'bcc' => $invoice->delivery->bcc ?? [],
                'message' => $invoice->delivery->message,
                'attempts' => $invoice->delivery->attempts,
                'last_attempted_at' => $invoice->delivery->last_attempted_at?->toIso8601String(),
                'sent_at' => $invoice->delivery->sent_at?->toIso8601String(),
                'error' => $invoice->delivery->error,
            ],
            approved_automatically: $invoice->approved_automatically,
            uses_time_tracking: TrackedHours::applies($invoice),
            tracked_at: $invoice->tracked_at?->toIso8601String(),
            sends_automatically: InvoiceAttention::sendsAutomatically($invoice),
            automation_reasons: InvoiceAttention::reasons($invoice),
            notes: $invoice->notes,
            voided_at: $invoice->voided_at?->toIso8601String(),
            void_reason: $invoice->void_reason,
            currency_code: $invoice->currency->value,
        );
    }
}
