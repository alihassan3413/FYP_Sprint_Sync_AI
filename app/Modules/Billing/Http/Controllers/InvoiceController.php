<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\CancelInvoiceSendingAction;
use App\Modules\Billing\Actions\UpdateInvoiceLinesAction;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\InvoiceSummaryData;
use App\Modules\Billing\Http\Requests\ApproveInvoiceRequest;
use App\Modules\Billing\Http\Requests\UpdateInvoiceLinesRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    /** "1 hour", "30 minutes", "2 hours" from finance.approval_send_delay_minutes. */
    private static function delayLabel(): string
    {
        $minutes = (int) config('finance.approval_send_delay_minutes');

        return $minutes % 60 === 0
            ? trans_choice('{1} 1 hour|[2,*] :count hours', intdiv($minutes, 60))
            : trans_choice('{1} 1 minute|[2,*] :count minutes', $minutes);
    }
}
