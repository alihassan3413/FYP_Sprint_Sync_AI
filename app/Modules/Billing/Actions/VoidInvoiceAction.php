<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Support\Facades\DB;

final class VoidInvoiceAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        $voided = DB::transaction(function () use ($invoice, $reason, $actor) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $locked->isManual()) {
                throw InvoiceException::notManual();
            }

            if ($locked->payments()->active()->exists()) {
                throw InvoiceException::hasPayments();
            }

            return Invoice::query()
                ->whereKey($locked->getKey())
                ->whereNull('voided_at')
                ->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason, 'updated_at' => now()]);
        });

        $fresh = Invoice::query()->with('workspace')->findOrFail($invoice->getKey());

        if ($voided === 1) {
            $this->auditLogger->handle(
                $fresh->workspace,
                null,
                $actor,
                AuditAction::INVOICE_VOIDED,
                "{$actor->name} voided invoice ".($fresh->number ?? "draft \"{$fresh->title}\"").": {$reason}",
                $fresh,
                ['number' => $fresh->number, 'reason' => $reason],
            );
        }

        return $fresh;
    }
}
