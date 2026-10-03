<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Renders an invoice document from its own snapshot only: its lines,
 * adjustments, bill_to and bill_from. Nothing live (plan, client, team,
 * workspace settings) is read, so the same invoice always renders the same.
 *
 * Anything not yet issued renders as a DRAFT without a business number.
 * dompdf runs with remote resources disabled; the logo is embedded as data.
 */
final class InvoicePdfRenderer
{
    public function __construct(private readonly InvoiceLogoStore $logos) {}

    public function html(Invoice $invoice): string
    {
        return view('billing.invoice-pdf', $this->viewData($invoice))->render();
    }

    public function pdf(Invoice $invoice): string
    {
        return Pdf::setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->setPaper('a4')
            ->loadHTML($this->html($invoice))
            ->output();
    }

    /** INV-2026-0001-RocketFlood.pdf, or Draft-RocketFlood-September-2026.pdf before it is issued. */
    public function filename(Invoice $invoice): string
    {
        $client = self::safe($invoice->bill_to['name'] ?? 'Client');

        return $invoice->isIssued() && $invoice->number !== null
            ? self::safe($invoice->number)."-{$client}.pdf"
            : "Draft-{$client}-".$invoice->period_start->format('F-Y').'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'adjustments']);
        $currency = $invoice->currency;
        $money = fn (?int $minor) => $minor === null ? '—' : MoneyFormatter::format($minor, $currency);

        return [
            'invoice' => $invoice,
            'isDraft' => $invoice->status !== InvoiceStatus::Issued,
            'isHourly' => $invoice->pricing_mode === PricingMode::Hourly,
            'from' => $invoice->bill_from,
            'to' => $invoice->bill_to,
            'logo' => $this->logos->dataUri($invoice->bill_from['logo_path'] ?? null),
            'period' => self::periodLabel($invoice),
            'lines' => $invoice->lines->map(fn ($line) => [
                'description' => $line->description,
                'role' => $line->role_label,
                'quantity' => $line->quantity_centi === null ? '—' : MoneyFormatter::quantity($line->quantity_centi),
                'rate' => $money($line->unit_price_minor),
                'amount' => $money($line->amount_minor),
            ])->all(),
            'adjustments' => $invoice->adjustments->map(fn ($adjustment) => [
                'label' => $adjustment->label.($adjustment->type->value === 'percentage' ? ' '.MoneyFormatter::percentage($adjustment->value).'%' : ''),
                'amount' => $money($adjustment->amount_minor),
            ])->all(),
            'subtotal' => $money($invoice->subtotal_minor),
            'total' => $money($invoice->total_minor),
            'issueDate' => $invoice->issue_date?->format('M j, Y'),
            'dueDate' => $invoice->due_date?->format('M j, Y'),
        ];
    }

    private static function periodLabel(Invoice $invoice): string
    {
        $start = CarbonImmutable::parse($invoice->period_start);
        $end = CarbonImmutable::parse($invoice->period_end);

        return $start->format('F j').'–'.$end->format('j, Y');
    }

    private static function safe(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($value)), '-') ?: 'Invoice';
    }
}
