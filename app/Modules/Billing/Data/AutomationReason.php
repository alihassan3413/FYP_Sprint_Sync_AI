<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why automatic sending held an invoice for the owner. Codes are stable (they
 * are stored on the invoice); messages are written when the check runs.
 *
 * A future time-tracking check ("Continuous tracked session over 12 hours")
 * becomes one more case here plus one more check in InvoiceAutomationSafety.
 */
#[TypeScript]
enum AutomationReason: string
{
    case FirstInvoice = 'first_invoice';
    case TotalVariance = 'total_variance';
    case HoursVariance = 'hours_variance';
    case MissingHours = 'missing_hours';
    case SendDateMissed = 'send_date_missed';
    case MailNotConnected = 'mail_not_connected';
    case SenderIncomplete = 'sender_incomplete';
    case RecipientInvalid = 'recipient_invalid';
    case TimeActivityReview = 'time_activity_review';
    /** Shown, not stored: missing hours that were entered after the send date. */
    case LateHours = 'late_hours';
}
