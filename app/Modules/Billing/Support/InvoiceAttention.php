<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\AutomationReason;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;

/**
 * What an invoice needs from the owner, in three parts: what (label), why
 * (reason) and the one obvious action. Null when it needs nothing. The
 * priority orders the Invoices home "Needs you" list:
 *
 * 1 needs hours · 2 held by automatic sending (needs review / ready to send)
 * · 3 ready to review · 4 email problem. An auto-send invoice still waiting
 * for its send date needs nothing yet.
 */
final class InvoiceAttention
{
    /**
     * @return array{kind: string, priority: int, label: string, reason: string, action: string}|null
     */
    public static function for(Invoice $invoice): ?array
    {
        $hold = collect($invoice->automation_hold ?? []);
        $codes = $hold->pluck('code');

        if ($invoice->status === InvoiceStatus::NeedsHours) {
            $missing = (int) ($invoice->lines_missing_hours ?? $invoice->lines->whereNull('quantity_centi')->count());
            $late = $codes->contains(AutomationReason::MissingHours->value) ? ' · send date passed' : '';

            return self::make('needs_hours', 1, 'Needs hours', "{$missing} ".($missing === 1 ? 'person' : 'people')." missing{$late}", 'Enter hours');
        }

        if ($invoice->status === InvoiceStatus::ReadyToReview && $hold->isNotEmpty()) {
            if ($codes->contains(AutomationReason::MissingHours->value)) {
                return self::make('ready_to_send', 2, 'Ready to send', self::lateHoursMessage(), 'Review and send');
            }

            $reason = $hold->first(fn ($item) => $item['code'] !== AutomationReason::SendDateMissed->value) ?? $hold->first();
            $label = $codes->every(fn ($code) => $code === AutomationReason::SendDateMissed->value) ? 'Ready to send' : 'Needs review';

            return self::make('safety_review', 2, $label, $reason['message'], 'Review');
        }

        if (self::sendsAutomatically($invoice)) {
            return null;
        }

        if ($invoice->status === InvoiceStatus::ReadyToReview) {
            return self::make('ready_to_review', 3, 'Ready to review', MoneyFormatter::format($invoice->total_minor, $invoice->currency).' · ready for you to approve', 'Review');
        }

        $delivery = $invoice->relationLoaded('delivery') ? $invoice->delivery : null;

        if ($delivery !== null && in_array($delivery->status, [DeliveryStatus::Failed, DeliveryStatus::NeedsAttention], true)) {
            return self::make('email_failed', 4, $delivery->status === DeliveryStatus::Failed ? 'Email failed' : 'Check email', (string) $delivery->error, 'Open invoice');
        }

        return null;
    }

    /**
     * The reasons to show on the invoice page while it waits for the owner.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function reasons(Invoice $invoice): array
    {
        if (! $invoice->status->isEditable() || empty($invoice->automation_hold)) {
            return [];
        }

        $hold = $invoice->automation_hold;

        if ($invoice->status === InvoiceStatus::ReadyToReview && in_array(AutomationReason::MissingHours->value, array_column($hold, 'code'), true)) {
            return [['code' => AutomationReason::LateHours->value, 'message' => self::lateHoursMessage()]];
        }

        return array_values($hold);
    }

    /**
     * Ready, set to send automatically and not yet decided: it is on its way
     * by itself (on its send date), so it does not need the owner.
     */
    public static function sendsAutomatically(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::ReadyToReview
            && $invoice->delivery_mode === DeliveryMode::AutoSend
            && $invoice->automation_checked_at === null
            && $invoice->billing_plan_id !== null;
    }

    public static function lateHoursMessage(): string
    {
        return 'Hours were completed after the scheduled send date. Review and send this invoice.';
    }

    /**
     * @return array{kind: string, priority: int, label: string, reason: string, action: string}
     */
    private static function make(string $kind, int $priority, string $label, string $reason, string $action): array
    {
        return compact('kind', 'priority', 'label', 'reason', 'action');
    }
}
