<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\OwnerReminders;
use Carbon\CarbonImmutable;

/**
 * Scheduled generation for one recurring plan: on or after its generation day
 * (in the workspace's calendar) the latest due month is prepared through
 * GenerateInvoiceFromPlanAction, so UNIQUE(billing_plan_id, period_start)
 * keeps it to one invoice however often this runs.
 *
 * Only the latest due month, and only if the plan's automation boundary is
 * before its generation date: a paused, archived or newly activated plan
 * never backfills older months. Hourly invoices start Needs hours and the
 * owner hears once that the hours are ready to complete.
 */
final class GenerateDueRecurringInvoiceAction
{
    public function __construct(
        private readonly GenerateInvoiceFromPlanAction $generate,
        private readonly BillingSchedule $schedule,
        private readonly OwnerReminders $reminders,
    ) {}

    public function handle(BillingPlan $plan, CarbonImmutable $now): ?Invoice
    {
        if ($plan->isPaused() || $plan->client->isArchived() || $plan->lines()->doesntExist()) {
            return null;
        }

        $today = $now->setTimezone($plan->workspace->timezone)->startOfDay();
        $cycle = $this->schedule->latestDueCycle($plan, $today);

        if (! $plan->automates($cycle->generationDate)) {
            return null;
        }

        $invoice = $this->generate->handle($plan, $cycle, null);

        if ($invoice->status === InvoiceStatus::NeedsHours) {
            $month = $invoice->period_start->format('F');
            $this->reminders->once(
                $plan->workspace,
                "invoice:{$invoice->id}:hours_open",
                'invoice_needs_hours',
                'Hours to complete',
                "{$invoice->title} · {$month} hours are ready to complete.",
                route('workspace.invoices.show', ['workspace' => $plan->workspace->slug, 'invoice' => $invoice->public_id]),
            );
        }

        return $invoice;
    }
}
