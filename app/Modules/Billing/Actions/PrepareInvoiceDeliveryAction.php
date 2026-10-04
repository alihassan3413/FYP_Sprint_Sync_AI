<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Exceptions\DeliveryException;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Gets an approved invoice ready to email: issue it, then create its single
 * delivery record. Used by the scheduled dispatcher and by Send now alike.
 *
 * - Nothing happens unless the workspace has connected email delivery: an
 *   invoice is never issued (and never takes a number) just to wait for mail.
 * - Issuing goes through IssueInvoiceAction (number, dates, official PDF).
 *   Its conditional update is also what settles a race with Cancel sending:
 *   whichever commits first wins, never both.
 * - The delivery row is unique per invoice; a repeat or concurrent call finds
 *   the existing one. Recipients come from the invoice's bill_to snapshot;
 *   the visible From from the mail settings at this moment; credentials are
 *   not copied anywhere.
 */
final class PrepareInvoiceDeliveryAction
{
    public function __construct(private readonly IssueInvoiceAction $issue) {}

    public function handle(Invoice $invoice, ?User $actor, bool $sendNow): InvoiceDelivery
    {
        $settings = $invoice->workspace->mailSettings;

        if ($settings === null) {
            throw DeliveryException::mailNotConnected();
        }

        $issued = $this->issue->handle($invoice, $actor, $sendNow);

        if ($existing = $issued->delivery()->first()) {
            return $existing;
        }

        try {
            return $issued->delivery()->forceCreate([
                'workspace_id' => $issued->workspace_id,
                'channel' => InvoiceDelivery::CHANNEL_EMAIL,
                'status' => DeliveryStatus::Pending,
                'recipient' => $issued->bill_to['billing_email'],
                'cc' => array_values(array_filter($issued->bill_to['cc_emails'] ?? [], fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))) ?: null,
                'subject' => "Invoice {$issued->number} from ".($issued->bill_from['business_name'] ?? $settings->from_name),
                'from_email' => $settings->from_email,
                'from_name' => $settings->from_name,
                'reply_to_email' => $settings->reply_to_email,
                'message_id' => 'invoice-'.$issued->public_id.'@'.(Str::after($settings->from_email, '@') ?: 'sprintsync.invalid'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $issued->delivery()->first() ?? throw $exception;
        }
    }
}
