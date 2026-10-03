<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\CancelInvoiceSendingAction;
use App\Modules\Billing\Actions\RefreshInvoiceSenderAction;
use App\Modules\Billing\Actions\UpdateInvoiceLinesAction;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\InvoiceSummaryData;
use App\Modules\Billing\Http\Requests\ApproveInvoiceRequest;
use App\Modules\Billing\Http\Requests\UpdateInvoiceLinesRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\OfficialInvoicePdf;
use App\Modules\Billing\Support\SenderSnapshot;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InvoiceController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        abort_unless($request->user()->can('viewAny', [Invoice::class, $workspace]), 403);

        $invoices = $workspace->invoices()
            ->withCount(['lines as lines_missing_hours' => fn ($query) => $query->whereNull('quantity_centi')])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get();

        $needsYou = $invoices->filter(fn (Invoice $invoice) => $invoice->status->isEditable());

        return Inertia::render('invoices/index', [
            'needsYou' => $needsYou->map(InvoiceSummaryData::fromModel(...))->values(),
            'invoices' => $invoices->map(InvoiceSummaryData::fromModel(...))->values(),
        ]);
    }

    public function show(Request $request, Workspace $workspace, Invoice $invoice): Response
    {
        abort_unless($request->user()->can('view', $invoice), 403);

        $invoice->load(['lines', 'adjustments', 'approver:id,name', 'client:id,public_id']);

        return Inertia::render('invoices/show', [
            'invoice' => InvoiceData::fromModel($invoice),
            'canEdit' => $request->user()->can('update', $invoice),
            'canApprove' => $request->user()->can('approve', $invoice),
            'sendDelayMinutes' => (int) config('finance.approval_send_delay_minutes'),
            'canManageInvoicing' => $request->user()->can('manage', [InvoicingProfile::class, $workspace]),
            'hasCompleteInvoicingDetails' => SenderSnapshot::isComplete(SenderSnapshot::from($workspace->invoicingProfile)),
        ]);
    }

    public function updateLines(UpdateInvoiceLinesRequest $request, Workspace $workspace, Invoice $invoice, UpdateInvoiceLinesAction $action): RedirectResponse
    {
        $action->handle($invoice, $request->values(), $request->user());

        return back();
    }

    public function approve(ApproveInvoiceRequest $request, Workspace $workspace, Invoice $invoice, ApproveInvoiceAction $action): RedirectResponse
    {
        $invoice = $action->handle($invoice, $request->integer('version'), $request->user());

        return back()->with('success', $invoice->isIssued()
            ? "This invoice was already issued as {$invoice->number}."
            : 'Approved. Scheduled to send in '.self::delayLabel().'. You can cancel sending if you need to make a change.');
    }

    public function cancelSending(Request $request, Workspace $workspace, Invoice $invoice, CancelInvoiceSendingAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('cancelSending', $invoice), 403);

        $action->handle($invoice, $request->user());

        return back()->with('success', 'Sending cancelled. You can edit the invoice again.');
    }

    /**
     * The invoice as a PDF. Before it is issued: a DRAFT rendered on demand.
     * Once issued: the stored official document, written once and never
     * regenerated (OfficialInvoicePdf).
     */
    public function pdf(Request $request, Workspace $workspace, Invoice $invoice, InvoicePdfRenderer $renderer, OfficialInvoicePdf $official): HttpResponse|StreamedResponse
    {
        abort_unless($request->user()->can('view', $invoice), 403);

        $filename = $renderer->filename($invoice);

        if (! $invoice->isIssued()) {
            return response($renderer->pdf($invoice->load(['lines', 'adjustments'])), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return Storage::disk(OfficialInvoicePdf::DISK)->download($official->ensure($invoice), $filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The logo this invoice was prepared with (its own snapshot), for the invoice page. */
    public function logo(Request $request, Workspace $workspace, Invoice $invoice): StreamedResponse
    {
        abort_unless($request->user()->can('view', $invoice), 403);

        $path = $invoice->bill_from['logo_path'] ?? null;
        abort_if($path === null || ! Storage::disk(InvoicingProfile::LOGO_DISK)->exists($path), 404);

        return Storage::disk(InvoicingProfile::LOGO_DISK)->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function refreshSender(Request $request, Workspace $workspace, Invoice $invoice, RefreshInvoiceSenderAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $invoice), 403);

        $action->handle($invoice, $request->user());

        return back()->with('success', 'This invoice now uses your current invoicing details.');
    }

    /** "1 hour", "30 minutes", "2 hours" from finance.approval_send_delay_minutes. */
    private static function delayLabel(): string
    {
        $minutes = (int) config('finance.approval_send_delay_minutes');

        return $minutes % 60 === 0
            ? trans_choice('{1} 1 hour|[2,*] :count hours', intdiv($minutes, 60))
            : trans_choice('{1} 1 minute|[2,*] :count minutes', $minutes);
    }
}
