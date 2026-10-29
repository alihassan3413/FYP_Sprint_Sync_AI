<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\FinalizeManualInvoiceAction;
use App\Modules\Billing\Actions\MarkInvoicePaidAction;
use App\Modules\Billing\Actions\SaveManualInvoiceAction;
use App\Modules\Billing\Actions\SendManualInvoiceAction;
use App\Modules\Billing\Actions\VoidInvoiceAction;
use App\Modules\Billing\Data\AdjustmentKind;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\PaymentMethod;
use App\Modules\Billing\Http\Requests\ManualInvoiceRequest;
use App\Modules\Billing\Http\Requests\MarkInvoicePaidRequest;
use App\Modules\Billing\Http\Requests\SendManualInvoiceRequest;
use App\Modules\Billing\Http\Requests\VoidInvoiceRequest;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceAdjustment;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Support\MoneyFormatter;
use App\Modules\Billing\Support\SenderSnapshot;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ManualInvoiceController
{
    public function create(Request $request, Workspace $workspace): Response
    {
        abort_unless($request->user()->can('create', [Invoice::class, $workspace]), 403);

        return Inertia::render('invoices/manual-form', [...$this->formOptions($workspace), 'invoice' => null]);
    }

    public function store(ManualInvoiceRequest $request, Workspace $workspace, SaveManualInvoiceAction $save): RedirectResponse
    {
        $invoice = $save->create($workspace, $request->details(), $request->validated('idempotency_key'), $request->user());

        return redirect()->route('workspace.invoices.show', [$workspace, $invoice])->with('success', 'Draft invoice saved.');
    }

    public function edit(Request $request, Workspace $workspace, Invoice $invoice): Response|RedirectResponse
    {
        abort_unless($request->user()->can('update', $invoice), 403);

        if (! $invoice->isManual() || $invoice->isVoided() || $invoice->status !== InvoiceStatus::ReadyToReview) {
            return redirect()->route('workspace.invoices.show', [$workspace, $invoice]);
        }

        $invoice->load(['lines', 'adjustments', 'client:id,public_id']);

        return Inertia::render('invoices/manual-form', [
            ...$this->formOptions($workspace),
            'invoice' => [
                'public_id' => $invoice->public_id,
                'version' => $invoice->version,
                'recipient_type' => $invoice->client_id === null ? 'custom' : 'client',
                'client' => $invoice->client?->public_id,
                'bill_to' => $invoice->bill_to,
                'title' => $invoice->title,
                'currency' => $invoice->currency->value,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'notes' => $invoice->notes,
                'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                    'description' => $line->description,
                    'quantity' => MoneyFormatter::quantity((int) $line->quantity_centi),
                    'rate' => MoneyFormatter::quantity($line->unit_price_minor),
                ])->values(),
                'adjustments' => $invoice->adjustments->map(fn (InvoiceAdjustment $adjustment) => [
                    'kind' => $adjustment->kind->value,
                    'type' => $adjustment->type->value,
                    'value' => MoneyFormatter::quantity($adjustment->value),
                    'label' => $adjustment->label,
                ])->values(),
            ],
        ]);
    }

    public function update(ManualInvoiceRequest $request, Workspace $workspace, Invoice $invoice, SaveManualInvoiceAction $save): RedirectResponse
    {
        $save->update($invoice, $request->details(), (int) $request->validated('version'), $request->user());

        return redirect()->route('workspace.invoices.show', [$workspace, $invoice])->with('success', 'Draft invoice updated.');
    }

    public function show(Request $request, Workspace $workspace, Invoice $invoice): Response
    {
        $invoice->load(['lines', 'adjustments', 'approver:id,name', 'client:id,public_id,billing_email,cc_emails', 'delivery', 'payments.recorder:id,name', 'payments.voider:id,name']);
        $user = $request->user();
        $draft = ! $invoice->isIssued() && ! $invoice->isVoided();

        return Inertia::render('invoices/manual', [
            'invoice' => InvoiceData::fromModel($invoice),
            'canEdit' => $draft && $user->can('update', $invoice),
            'canSend' => ! $invoice->isVoided() && $invoice->delivery === null && $user->can('send', $invoice),
            'canFinalize' => $draft && $user->can('send', $invoice),
            'canVoid' => ! $invoice->isVoided() && $user->can('void', $invoice),
            'canRecordPayments' => $invoice->isIssued() && ! $invoice->isVoided() && $user->can('recordPayment', $invoice),
            'paymentMethods' => PaymentMethod::options(),
            'today' => CarbonImmutable::now($workspace->timezone)->toDateString(),
            'mailConnected' => $workspace->mailSettings()->exists(),
            'hasCompleteInvoicingDetails' => SenderSnapshot::isComplete(SenderSnapshot::from($workspace->invoicingProfile)),
            'suggestedRecipients' => [
                'to' => array_values(array_filter([$invoice->client?->billing_email ?? ($invoice->bill_to['billing_email'] ?? null)])),
                'cc' => array_values($invoice->client?->cc_emails ?? ($invoice->bill_to['cc_emails'] ?? [])),
            ],
        ]);
    }

    public function finalize(Request $request, Workspace $workspace, Invoice $invoice, FinalizeManualInvoiceAction $finalize): RedirectResponse
    {
        abort_unless($request->user()->can('send', $invoice), 403);

        $finalized = $finalize->handle($invoice, $request->user());

        return back()->with('success', "Invoice {$finalized->number} is final. Download the PDF or send it.");
    }

    public function send(SendManualInvoiceRequest $request, Workspace $workspace, Invoice $invoice, SendManualInvoiceAction $send): RedirectResponse
    {
        $send->handle($invoice, $request->addresses('to'), $request->addresses('cc'), $request->addresses('bcc'), $request->validated('message'), $request->user());

        return back()->with('success', 'Invoice is on its way.');
    }

    public function markPaid(MarkInvoicePaidRequest $request, Workspace $workspace, Invoice $invoice, MarkInvoicePaidAction $markPaid): RedirectResponse
    {
        $markPaid->handle($invoice, $request->validated('idempotency_key'), $request->receivedOn(), PaymentMethod::from($request->validated('method')), $request->validated('reference'), $request->user());

        return back()->with('success', 'Invoice marked as paid.');
    }

    public function void(VoidInvoiceRequest $request, Workspace $workspace, Invoice $invoice, VoidInvoiceAction $void): RedirectResponse
    {
        $void->handle($invoice, $request->validated('reason'), $request->user());

        return back()->with('success', 'Invoice voided. It stays on record and no longer counts as owed.');
    }

    private function formOptions(Workspace $workspace): array
    {
        $today = CarbonImmutable::now($workspace->timezone);

        return [
            'clients' => $workspace->clients()->whereNull('archived_at')->orderBy('name')->get()->map(fn (Client $client) => [
                'public_id' => $client->public_id,
                'name' => $client->name,
                'currency' => $client->currency->value,
                'billing_email' => $client->billing_email,
            ])->values(),
            'currencies' => Currency::options(),
            'adjustmentKinds' => array_map(fn (AdjustmentKind $kind) => ['value' => $kind->value, 'label' => ucfirst($kind->value)], AdjustmentKind::cases()),
            'defaults' => ['issue_date' => $today->toDateString(), 'due_date' => $today->addDays(15)->toDateString()],
            'hasCompleteInvoicingDetails' => SenderSnapshot::isComplete(SenderSnapshot::from($workspace->invoicingProfile)),
        ];
    }
}
