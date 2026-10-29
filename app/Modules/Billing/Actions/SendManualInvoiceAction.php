<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Exceptions\DeliveryException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Jobs\SendInvoiceEmailJob;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final class SendManualInvoiceAction
{
    public function __construct(
        private readonly FinalizeManualInvoiceAction $finalize,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Invoice $invoice, array $to, array $cc, array $bcc, ?string $message, User $actor): InvoiceDelivery
    {
        $settings = $invoice->workspace->mailSettings;

        if ($settings === null) {
            throw DeliveryException::mailNotConnected();
        }

        if ($invoice->isVoided()) {
            throw InvoiceException::voided();
        }

        $issued = $this->finalize->handle($invoice, $actor);

        if ($existing = $issued->delivery()->first()) {
            return $existing;
        }

        try {
            $delivery = $issued->delivery()->forceCreate([
                'workspace_id' => $issued->workspace_id,
                'channel' => InvoiceDelivery::CHANNEL_EMAIL,
                'status' => DeliveryStatus::Pending,
                'recipient' => $to[0],
                'recipients' => $to,
                'cc' => $cc ?: null,
                'bcc' => $bcc ?: null,
                'subject' => "Invoice {$issued->number} from ".($issued->bill_from['business_name'] ?? $settings->from_name),
                'message' => $message,
                'from_email' => $settings->from_email,
                'from_name' => $settings->from_name,
                'reply_to_email' => $settings->reply_to_email,
                'message_id' => 'invoice-'.$issued->public_id.'@'.(Str::after($settings->from_email, '@') ?: 'sprintsync.invalid'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $issued->delivery()->first() ?? throw $exception;
        }

        $this->auditLogger->handle(
            $issued->workspace,
            null,
            $actor,
            AuditAction::INVOICE_SEND_QUEUED,
            "{$actor->name} sent invoice {$issued->number} to ".implode(', ', $to).'.',
            $issued,
            ['to' => $to, 'cc' => $cc, 'bcc_count' => count($bcc), 'has_message' => $message !== null],
        );

        SendInvoiceEmailJob::dispatch($delivery->id);

        return $delivery;
    }
}
