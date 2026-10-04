<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\RetryInvoiceDeliveryAction;
use App\Modules\Billing\Actions\SendInvoiceNowAction;
use App\Modules\Billing\Exceptions\DeliveryException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InvoiceDeliveryController
{
    public function sendNow(Request $request, Workspace $workspace, Invoice $invoice, SendInvoiceNowAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('send', $invoice), 403);

        $action->handle($invoice, $request->user());

        return back()->with('success', 'Sending the invoice now.');
    }

    public function retry(Request $request, Workspace $workspace, Invoice $invoice, RetryInvoiceDeliveryAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('send', $invoice), 403);

        $delivery = $invoice->delivery()->first() ?? throw DeliveryException::notRetryable();
        $action->handle($delivery, $request->boolean('confirmed'), $request->user());

        return back()->with('success', 'Sending the invoice again.');
    }
}
