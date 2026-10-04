<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\OwnerReminders;
use Carbon\CarbonImmutable;

/**
 * Owner reminders for "Review before sending" invoices. Each is sent at most
 * once (OwnerReminders) and only on its own day, so a missed day is skipped
 * rather than sent late, and there are never more than three per month:
 *
 * - upcoming: N days before the send date (the plan's "Remind me" setting).
 *   For fixed plans the invoice does not exist yet, so it is about the plan;
 * - due day: the invoice is ready to review, or still needs hours;
 * - follow-up: the next day, once, if it is still waiting. Then nothing more.
 *
 * Only months the automation handles (after the plan's boundary) and only
 * while the plan is active, so turning this on never reminds about old work.
 */
final class SendRecurringRemindersAction
{
    public function __construct(
        private readonly BillingSchedule $schedule,
        private readonly OwnerReminders $reminders,
    ) {}

    public function forInvoice(Invoice $invoice, CarbonImmutable $now): void
    {
        $plan = $invoice->plan;

        if ($invoice->delivery_mode !== DeliveryMode::ReviewBeforeSending || $plan === null || $plan->isPaused()
            || ! $invoice->status->isEditable() || ! $plan->automates($invoice->generated_on)) {
            return;
        }

        $today = $now->setTimezone($invoice->workspace->timezone)->toDateString();
        $sendOn = $invoice->planned_send_on->toImmutable();
        $kind = match ($today) {
            $sendOn->toDateString() => 'due',
            $sendOn->addDay()->toDateString() => 'followup',
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $month = $invoice->period_start->format('F');
        $needsHours = $invoice->status === InvoiceStatus::NeedsHours;
        $message = $needsHours
            ? "{$invoice->title} for {$month} still needs hours before it can be sent."
            : "{$invoice->title} for {$month} is ready to review.";

        $this->reminders->once(
            $invoice->workspace,
            "invoice:{$invoice->id}:{$kind}",
            $needsHours ? 'invoice_needs_hours' : 'invoice_ready_to_review',
            $kind === 'followup' ? 'Still waiting for you' : ($needsHours ? 'Hours needed' : 'Ready to review'),
            $message,
            route('workspace.invoices.show', ['workspace' => $invoice->workspace->slug, 'invoice' => $invoice->public_id]),
        );
    }

    public function forPlan(BillingPlan $plan, CarbonImmutable $now): void
    {
        $days = (int) $plan->reminder_days_before;

        if ($plan->delivery_mode !== DeliveryMode::ReviewBeforeSending || $plan->isPaused() || $days === 0 || $plan->client->isArchived()) {
            return;
        }

        $today = $now->setTimezone($plan->workspace->timezone)->startOfDay();
        $cycle = $this->schedule->nextCycleForPlan($plan, $today);

        if (! $cycle->sendDate->subDays($days)->isSameDay($today) || ! $plan->automates($cycle->generationDate)) {
            return;
        }

        $this->reminders->once(
            $plan->workspace,
            "plan:{$plan->id}:upcoming:{$cycle->periodStart->toDateString()}",
            'invoice_upcoming',
            'Invoice coming up',
            "{$plan->name} for {$cycle->periodStart->format('F')} will be ready to review on {$cycle->sendDate->format('M j')}.",
            route('workspace.clients.show', ['workspace' => $plan->workspace->slug, 'client' => $plan->client->public_id]),
        );
    }
}
