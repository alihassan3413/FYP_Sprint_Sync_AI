<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\RecordPaymentAction;
use App\Modules\Billing\Actions\VoidPaymentAction;
use App\Modules\Billing\Data\PaymentMethod;
use App\Modules\Billing\Http\Requests\RecordPaymentRequest;
use App\Modules\Billing\Http\Requests\VoidPaymentRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\MoneyFormatter;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;

final class PaymentController
{
    public function store(RecordPaymentRequest $request, Workspace $workspace, Invoice $invoice, RecordPaymentAction $action): RedirectResponse
    {
        $payment = $action->handle(
            $invoice,
            $request->validated('idempotency_key'),
            $request->amountMinor(),
            $request->receivedOn(),
            PaymentMethod::from($request->validated('method')),
            $request->validated('reference'),
            $request->validated('note'),
            $request->user(),
        );

        return back()->with('success', 'Payment of '.MoneyFormatter::format($payment->amount_minor, $payment->currency).' recorded.');
    }

    public function void(VoidPaymentRequest $request, Workspace $workspace, Invoice $invoice, Payment $payment, VoidPaymentAction $action): RedirectResponse
    {
        $action->handle($payment, $request->validated('reason'), $request->user());

        return back()->with('success', 'Payment voided. It stays in the history but no longer counts as paid.');
    }
}
