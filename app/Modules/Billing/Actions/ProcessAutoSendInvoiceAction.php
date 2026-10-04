<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Data\SystemActor;
use App\Modules\Billing\Data\AutomationReason;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Data\SafetyResult;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\InvoiceAutomationSafety;
use App\Modules\Billing\Support\OwnerReminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Automatic sending's one decision for an invoice, made on its planned send
 * date (from AUTO_SEND_FROM_HOUR in the workspace's timezone, so the owner is
 * awake for the cancel window):
 *
 * - safe → approved through ApproveInvoiceAction as SprintSync. The usual
 *   cancel window applies, then the delivery pipeline issues and emails it;
 * - not safe → held: the reasons are stored on the invoice, it stays where it
 *   is (Ready to review / Needs hours) and the owner is told once.
 *
 * A plan's first invoice is always held, and that is known at once, so it is
 * held as soon as it exists rather than on its send date: the owner sees
 * "review the first invoice" straight away.
 *
 * The decision is claimed first (automation_checked_at, a conditional update
 * inside the same transaction), so two workers or a rerun decide once. It is
 * never made again: hours finished late, an invoice first seen after its send
 * date, or one the owner cancelled all wait for a person.
 */
final class ProcessAutoSendInvoiceAction
{
    public const AUTO_SEND_FROM_HOUR = 9;

    public function __construct(
        private readonly InvoiceAutomationSafety $safety,
        private readonly ApproveInvoiceAction $approve,
        private readonly RecordAuditLogAction $auditLogger,
        private readonly OwnerReminders $reminders,
    ) {}

    /**
     * @return 'approved'|'held'|null null when there was nothing to decide (yet)
     */
    public function handle(Invoice $invoice, CarbonImmutable $now): ?string
    {
        $plan = $invoice->plan;

        if ($invoice->delivery_mode !== DeliveryMode::AutoSend || $plan === null || $plan->isPaused()
            || $invoice->automation_checked_at !== null || ! $invoice->status->isEditable()
            || ! $plan->automates($invoice->generated_on)) {
            return null;
        }

        $local = $now->setTimezone($invoice->workspace->timezone);
        $sendOn = $invoice->planned_send_on->toDateString();
        $first = $this->safety->previousIssued($invoice) === null;

        if (! $first && ($local->toDateString() < $sendOn || ($local->toDateString() === $sendOn && $local->hour < self::AUTO_SEND_FROM_HOUR))) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $local, $first) {
            $claimed = Invoice::query()
                ->whereKey($invoice->getKey())
                ->whereNull('automation_checked_at')
                ->whereIn('status', [InvoiceStatus::NeedsHours->value, InvoiceStatus::ReadyToReview->value])
                ->update(['automation_checked_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            $invoice = Invoice::query()->with(['lines', 'workspace.mailSettings', 'workspace.invoicingProfile'])->findOrFail($invoice->getKey());
            $result = $first
                ? new SafetyResult([['code' => AutomationReason::FirstInvoice->value, 'message' => InvoiceAutomationSafety::FIRST_INVOICE_MESSAGE]])
                : $this->safety->evaluate($invoice, $local->startOfDay());

            if ($result->isSafe()) {
                $this->approve->handle($invoice, $invoice->version, new SystemActor);

                return 'approved';
            }

            Invoice::query()->whereKey($invoice->getKey())->update(['automation_hold' => json_encode($result->reasons)]);

            $month = $invoice->period_start->format('F');
            $this->auditLogger->handle(
                $invoice->workspace,
                null,
                new SystemActor,
                AuditAction::INVOICE_AUTOMATION_HELD,
                "SprintSync held \"{$invoice->title}\" for {$month} for review instead of sending it automatically.",
                $invoice,
                ['reasons' => array_column($result->reasons, 'code')],
            );

            $this->reminders->once(
                $invoice->workspace,
                "invoice:{$invoice->id}:held",
                'invoice_auto_send_paused',
                'Automatic sending paused',
                "{$invoice->title} for {$month} needs your review. {$result->reasons[0]['message']}",
                route('workspace.invoices.show', ['workspace' => $invoice->workspace->slug, 'invoice' => $invoice->public_id]),
            );

            return 'held';
        });
    }
}
