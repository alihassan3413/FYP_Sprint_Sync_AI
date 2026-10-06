<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Billing\Actions\SendRecurringRemindersAction;
use App\Modules\Billing\Data\DeliveryMode;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sends the owner's review reminders (upcoming, due day, one follow-up).
 * Each reminder has a unique key, so reruns send nothing new.
 */
final class SendBillingRemindersCommand extends Command
{
    protected $signature = 'billing:send-owner-reminders';

    protected $description = 'Remind the owner about recurring invoices waiting for review';

    public function handle(SendRecurringRemindersAction $reminders): int
    {
        $now = CarbonImmutable::now();

        Invoice::query()
            ->where('delivery_mode', DeliveryMode::ReviewBeforeSending->value)
            ->whereIn('status', [InvoiceStatus::NeedsHours->value, InvoiceStatus::ReadyToReview->value])
            ->whereNotNull('billing_plan_id')
            ->whereDate('planned_send_on', '>=', $now->subDays(2)->toDateString())
            ->whereDate('planned_send_on', '<=', $now->addDay()->toDateString())
            ->with(['plan.workspace', 'workspace'])
            ->lazyById()
            ->each(fn (Invoice $invoice) => $reminders->forInvoice($invoice, $now));

        BillingPlan::query()
            ->whereNull('paused_at')
            ->where('delivery_mode', DeliveryMode::ReviewBeforeSending->value)
            ->where('reminder_days_before', '>', 0)
            ->with(['workspace', 'client'])
            ->lazyById()
            ->each(fn (BillingPlan $plan) => $reminders->forPlan($plan, $now));

        return self::SUCCESS;
    }
}
