{{--
    The printable invoice. Rendered by dompdf, so: tables for layout, plain CSS,
    no external resources (the logo arrives as a data URI). Everything comes
    from the invoice's own snapshot; see InvoicePdfRenderer.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $isDraft ? 'Draft invoice' : 'Invoice '.$invoice->number }}</title>
<style>
    @page { margin: 22mm 18mm 20mm 18mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2328; line-height: 1.45; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }
    .muted { color: #6b7280; }
    .small { font-size: 8.5pt; }
    .right { text-align: right; }
    .label { font-size: 7.5pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #6b7280; font-weight: bold; }
    .title { font-size: 22pt; letter-spacing: 3pt; font-weight: bold; color: #111827; }
    .draft-tag { display: inline-block; margin-top: 4pt; padding: 2pt 7pt; border: 1pt solid #d1d5db; border-radius: 3pt; font-size: 8pt; letter-spacing: 1.5pt; color: #6b7280; }
    .logo { max-width: 150pt; max-height: 56pt; margin-bottom: 8pt; }
    .business { font-size: 11pt; font-weight: bold; color: #111827; }
    .meta td { padding: 1.5pt 0; }
    .meta td.key { color: #6b7280; padding-right: 12pt; }
    .divider { border-top: 1pt solid #e5e7eb; margin: 18pt 0 14pt; }
    .lines th { font-size: 7.5pt; letter-spacing: 1pt; text-transform: uppercase; color: #6b7280; font-weight: bold; padding: 0 0 6pt; border-bottom: 1pt solid #d1d5db; }
    .lines td { padding: 8pt 0; border-bottom: 0.6pt solid #eef0f2; }
    .lines tr { page-break-inside: avoid; }
    .lines .desc { padding-right: 10pt; }
    .lines .role { color: #6b7280; font-size: 8.5pt; }
    .num { text-align: right; white-space: nowrap; padding-left: 10pt !important; }
    .totals { width: 46%; margin-left: 54%; margin-top: 10pt; page-break-inside: avoid; }
    .totals td { padding: 3pt 0; }
    .totals .grand td { border-top: 1pt solid #111827; padding-top: 7pt; font-size: 12pt; font-weight: bold; color: #111827; }
    .watermark { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 96pt; font-weight: bold; letter-spacing: 12pt; color: #111827; opacity: 0.05; transform: rotate(-30deg); }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; text-align: center; font-size: 7.5pt; color: #9ca3af; }
    .pre { white-space: pre-line; }
</style>
</head>
<body>
@if ($isDraft)
    <div class="watermark">DRAFT</div>
@endif

<table>
    <tr>
        <td style="width: 55%;">
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @endif
            @if ($from)
                <div class="business">{{ $from['business_name'] }}</div>
                @if (! empty($from['legal_name']))<div class="small muted">{{ $from['legal_name'] }}</div>@endif
                <div class="small">
                    {{ $from['address_line1'] }}@if (! empty($from['address_line2'])), {{ $from['address_line2'] }}@endif<br>
                    {{ collect([$from['city'], $from['region'] ?? null, $from['postal_code'] ?? null])->filter()->join(', ') }}<br>
                    {{ $from['country'] }}
                </div>
                <div class="small muted">{{ $from['billing_email'] }}@if (! empty($from['phone'])) · {{ $from['phone'] }}@endif</div>
                @if (! empty($from['tax_id']))<div class="small muted">Tax ID {{ $from['tax_id'] }}</div>@endif
            @else
                <div class="small muted">Sender details not set</div>
            @endif
        </td>
        <td style="width: 45%;" class="right">
            <div class="title">INVOICE</div>
            @if ($isDraft)
                <div class="draft-tag">DRAFT</div>
            @else
                <div style="font-size: 11pt; font-weight: bold; margin-top: 2pt;">{{ $invoice->number }}</div>
            @endif
            <table class="meta" style="width: auto; margin-left: auto; margin-top: 10pt;">
                @if ($issueDate)
                    <tr><td class="key">Issue date</td><td class="right">{{ $issueDate }}</td></tr>
                    <tr><td class="key">Due date</td><td class="right">{{ $dueDate }}</td></tr>
                @else
                    <tr><td class="key">Payment due</td><td class="right">{{ $invoice->due_in_days }} days after issue</td></tr>
                @endif
                <tr><td class="key">Period</td><td class="right">{{ $period }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="divider"></div>

<table>
    <tr>
        <td style="width: 55%;">
            <div class="label">Bill to</div>
            <div class="business" style="margin-top: 3pt;">{{ $to['name'] }}</div>
            <div class="small">{{ $to['billing_email'] }}</div>
            @if (! empty($to['address']))<div class="small pre">{{ $to['address'] }}</div>@endif
            @if (! empty($to['tax_id']))<div class="small muted">Tax ID {{ $to['tax_id'] }}</div>@endif
        </td>
        <td style="width: 45%;" class="right">
            <div class="label">For</div>
            <div style="margin-top: 3pt;">{{ $invoice->title }}</div>
        </td>
    </tr>
</table>

<table class="lines" style="margin-top: 20pt;">
    <thead>
        <tr>
            <th style="text-align: left;">Description</th>
            @if ($isHourly)
                <th class="num">Hours</th>
                <th class="num">Rate</th>
            @endif
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td class="desc">
                    {{ $line['description'] }}
                    @if ($line['role'])<div class="role">{{ $line['role'] }}</div>@endif
                </td>
                @if ($isHourly)
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $line['rate'] }}</td>
                @endif
                <td class="num">{{ $line['amount'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="muted">Subtotal</td><td class="num">{{ $subtotal }}</td></tr>
    @foreach ($adjustments as $adjustment)
        <tr><td class="muted">{{ $adjustment['label'] }}</td><td class="num">{{ $adjustment['amount'] }}</td></tr>
    @endforeach
    <tr class="grand"><td>Total {{ $invoice->currency->value }}</td><td class="num">{{ $total }}</td></tr>
</table>

<div class="footer">{{ $isDraft ? 'Draft — not a valid invoice until issued.' : ($from['business_name'] ?? '').' · '.$invoice->number }}</div>
</body>
</html>
