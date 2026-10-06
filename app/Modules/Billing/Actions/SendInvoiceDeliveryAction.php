<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\SystemActor;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Mail\InvoiceEmail;
use App\Modules\Billing\Models\InvoiceDelivery;
use App\Modules\Billing\Support\MailDeliveryFailure;
use App\Modules\Billing\Support\OfficialInvoicePdf;
use App\Modules\Billing\Support\OwnerReminders;
use App\Modules\Billing\Support\WorkspaceMailer;
use App\Notifications\InvoiceSentNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one invoice email, at most once at a time:
 *
 * 1. Claim: a conditional update pending → sending. Only one caller wins; a
 *    second worker, a rerun scheduler or a double click finds nothing to do.
 * 2. Send the official PDF through the workspace's own email server.
 * 3. Record the outcome with a conditional update from sending:
 *    - sent: the server accepted it;
 *    - failed: it definitely did not go out (safe to retry);
 *    - needs_attention: the connection broke after the message was handed
 *      over, so the client may have it. Never retried automatically.
 *
 * The invoice is already issued and is never touched here: a failed email
 * leaves it Issued with the same number, dates and PDF.
 */
final class SendInvoiceDeliveryAction
{
    public function __construct(
        private readonly WorkspaceMailer $mailer,
        private readonly OfficialInvoicePdf $pdf,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly OwnerReminders $reminders,
    ) {}

    public function handle(InvoiceDelivery $delivery): InvoiceDelivery
    {
        $claimed = InvoiceDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', DeliveryStatus::Pending->value)
            ->update([
                'status' => DeliveryStatus::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'last_attempted_at' => now(),
                'updated_at' => now(),
            ]);

        $delivery->refresh();

        if ($claimed !== 1) {
            return $delivery;
        }

        $invoice = $delivery->invoice()->with('workspace.mailSettings', 'workspace.owner')->firstOrFail();
        $settings = $invoice->workspace->mailSettings;

        try {
            if ($settings === null) {
                throw new MailDeliveryFailure('Email delivery is not connected for this workspace.', false);
            }

            $this->pdf->ensure($invoice);
            $this->mailer->send($settings, new InvoiceEmail($invoice->refresh(), $delivery));
        } catch (MailDeliveryFailure $failure) {
            return $this->finish($delivery, $failure->ambiguous ? DeliveryStatus::NeedsAttention : DeliveryStatus::Failed, $failure->reason);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish($delivery, DeliveryStatus::Failed, 'The invoice email could not be prepared.');
        }

        return $this->finish($delivery, DeliveryStatus::Sent, null);
    }

    private function finish(InvoiceDelivery $delivery, DeliveryStatus $outcome, ?string $reason): InvoiceDelivery
    {
        InvoiceDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', DeliveryStatus::Sending->value)
            ->update([
                'status' => $outcome->value,
                'sent_at' => $outcome === DeliveryStatus::Sent ? now() : null,
                'failed_at' => $outcome === DeliveryStatus::Sent ? null : now(),
                'error' => $reason,
                'updated_at' => now(),
            ]);

        $delivery->refresh();
        $invoice = $delivery->invoice()->with('workspace.owner')->firstOrFail();
        $label = "invoice {$invoice->number} to {$invoice->bill_to['name']}";

        $this->auditLogger->handle(
            $invoice->workspace,
            null,
            new SystemActor,
            match ($outcome) {
                DeliveryStatus::Sent => AuditAction::INVOICE_DELIVERY_SENT,
                DeliveryStatus::NeedsAttention => AuditAction::INVOICE_DELIVERY_UNCERTAIN,
                default => AuditAction::INVOICE_DELIVERY_FAILED,
            },
            match ($outcome) {
                DeliveryStatus::Sent => "Emailed {$label} ({$delivery->recipient}).",
                DeliveryStatus::NeedsAttention => "Could not confirm whether {$label} was emailed.",
                default => "Could not email {$label}.",
            },
            $delivery,
            ['invoice' => $invoice->number, 'recipient' => $delivery->recipient, 'attempt' => $delivery->attempts, 'reason' => $reason],
        );

        if ($outcome !== DeliveryStatus::Sent) {
            $this->reminders->once(
                $invoice->workspace,
                "delivery:{$delivery->id}:attempt:{$delivery->attempts}",
                'invoice_email_problem',
                'Invoice email needs attention',
                "{$invoice->number} to {$invoice->bill_to['name']} wasn't sent. {$reason}",
                route('workspace.invoices.show', ['workspace' => $invoice->workspace->slug, 'invoice' => $invoice->public_id]),
            );
        }

        if ($outcome === DeliveryStatus::Sent && $invoice->workspace->owner) {
            $invoice->workspace->owner->notify(new InvoiceSentNotification(
                (string) $invoice->number,
                (string) $invoice->bill_to['name'],
                route('workspace.invoices.show', ['workspace' => $invoice->workspace->slug, 'invoice' => $invoice->public_id]),
            ));
        }

        return $delivery;
    }
}
