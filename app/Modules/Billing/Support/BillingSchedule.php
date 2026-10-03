<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Data\BillingCycle;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\BillingPlan;
use Carbon\CarbonImmutable;

/**
 * Pure date arithmetic for recurring invoices. Every "today" passed in must
 * already be the workspace's local date; days stop at the 28th so every month
 * has them.
 *
 * Each month has two moments:
 * - generation day: the draft is created (hourly plans gather last month's hours)
 * - send day: the invoice is planned to go out (later the same month, or the same day)
 *
 * Fixed plans generate and send on one day. Hourly plans always generate on the
 * 1st, the first day last month's hours are complete, and send on their send
 * day (the 3rd by default), leaving time to check hours in between.
 *
 * Phase 4 asks two questions: cycleGeneratedOn($today) — "generate a draft
 * today?" — and BillingCycle::isSendDue($today) on the cycle snapshotted onto
 * the invoice — "may it be sent?". Idempotency of both lives with the invoice
 * (UNIQUE(plan, period_start) and a conditional send), not here.
 */
final class BillingSchedule
{
    public const HOURLY_GENERATION_DAY = 1;

    public const HOURLY_DEFAULT_SEND_DAY = 3;

    public static function generationDayFor(PricingMode $mode, int $sendDay): int
    {
        return $mode === PricingMode::Hourly ? self::HOURLY_GENERATION_DAY : $sendDay;
    }

    /**
     * The cycle whose draft is generated on $date, or null when $date is not
     * the plan's generation day.
     */
    public function cycleGeneratedOn(BillingPlan $plan, CarbonImmutable $date): ?BillingCycle
    {
        if ($date->day !== $plan->generation_day) {
            return null;
        }

        return $this->cycleFor($plan->generation_day, $plan->send_day, $plan->billing_period, $date->startOfMonth());
    }

    /**
     * The first cycle generated strictly after $today, so a plan created on its
     * own generation day starts next month rather than surprising anyone today.
     */
    public function nextCycle(int $generationDay, int $sendDay, BillingPeriod $period, CarbonImmutable $today): BillingCycle
    {
        $month = $today->startOfMonth();

        if (! $month->setDay($generationDay)->greaterThan($today->startOfDay())) {
            $month = $month->addMonthNoOverflow();
        }

        return $this->cycleFor($generationDay, $sendDay, $period, $month);
    }

    public function nextCycleForPlan(BillingPlan $plan, CarbonImmutable $today): BillingCycle
    {
        return $this->nextCycle($plan->generation_day, $plan->send_day, $plan->billing_period, $today);
    }

    private function cycleFor(int $generationDay, int $sendDay, BillingPeriod $period, CarbonImmutable $month): BillingCycle
    {
        $billed = $period === BillingPeriod::PreviousMonth ? $month->subMonthNoOverflow() : $month;

        return new BillingCycle(
            periodStart: $billed,
            periodEnd: $billed->endOfMonth()->startOfDay(),
            generationDate: $month->setDay($generationDay),
            sendDate: $month->setDay($sendDay),
        );
    }
}
