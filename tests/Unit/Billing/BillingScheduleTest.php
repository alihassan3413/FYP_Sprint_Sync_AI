<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\PricingMode;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Support\BillingSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Generation day and send day are separate: RocketFlood's Dev & Office plan is
 * generated and sent on the 1st; the CSR Team draft is generated on the 1st,
 * when September's hours are complete, and planned to send on the 3rd.
 */
final class BillingScheduleTest extends TestCase
{
    private BillingSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new BillingSchedule;
    }

    private function plan(PricingMode $mode, int $sendDay, BillingPeriod $period = BillingPeriod::PreviousMonth): BillingPlan
    {
        return (new BillingPlan)->forceFill([
            'pricing_mode' => $mode,
            'generation_day' => BillingSchedule::generationDayFor($mode, $sendDay),
            'send_day' => $sendDay,
            'billing_period' => $period,
        ]);
    }

    public function test_the_generation_day_is_derived_from_the_pricing_mode(): void
    {
        $this->assertSame(1, BillingSchedule::generationDayFor(PricingMode::Fixed, 1));
        $this->assertSame(15, BillingSchedule::generationDayFor(PricingMode::Fixed, 15));
        $this->assertSame(1, BillingSchedule::generationDayFor(PricingMode::Hourly, 3));
        $this->assertSame(1, BillingSchedule::generationDayFor(PricingMode::Hourly, 10));
    }

    public function test_dev_and_office_generates_and_sends_on_november_1st_for_october(): void
    {
        $cycle = $this->schedule->nextCycleForPlan($this->plan(PricingMode::Fixed, 1), CarbonImmutable::parse('2026-10-03'));

        $this->assertSame('2026-11-01', $cycle->generationDate->toDateString());
        $this->assertSame('2026-11-01', $cycle->sendDate->toDateString());
        $this->assertSame('2026-10-01', $cycle->periodStart->toDateString());
        $this->assertSame('2026-10-31', $cycle->periodEnd->toDateString());
    }

    public function test_the_csr_team_draft_is_generated_november_1st_and_sent_november_3rd(): void
    {
        $cycle = $this->schedule->nextCycleForPlan($this->plan(PricingMode::Hourly, 3), CarbonImmutable::parse('2026-10-03'));

        $this->assertSame('2026-11-01', $cycle->generationDate->toDateString());
        $this->assertSame('2026-11-03', $cycle->sendDate->toDateString());
        $this->assertSame('2026-10-01', $cycle->periodStart->toDateString());
        $this->assertSame('2026-10-31', $cycle->periodEnd->toDateString());
    }

    public function test_phase_4_can_ask_whether_to_generate_today(): void
    {
        $csr = $this->plan(PricingMode::Hourly, 3);

        $generated = $this->schedule->cycleGeneratedOn($csr, CarbonImmutable::parse('2026-10-01'));
        $this->assertNotNull($generated);
        $this->assertSame('2026-09-01', $generated->periodStart->toDateString(), 'October 1st generates the September draft');
        $this->assertSame('2026-10-03', $generated->sendDate->toDateString());

        $this->assertNull($this->schedule->cycleGeneratedOn($csr, CarbonImmutable::parse('2026-10-02')));
        $this->assertNull($this->schedule->cycleGeneratedOn($csr, CarbonImmutable::parse('2026-10-03')), 'the 3rd sends; it does not generate');
    }

    public function test_phase_4_can_ask_whether_the_draft_may_be_sent(): void
    {
        $cycle = $this->schedule->cycleGeneratedOn($this->plan(PricingMode::Hourly, 3), CarbonImmutable::parse('2026-10-01'));

        $this->assertFalse($cycle->isSendDue(CarbonImmutable::parse('2026-10-01 09:00')), 'hours can still be checked on the 1st');
        $this->assertFalse($cycle->isSendDue(CarbonImmutable::parse('2026-10-02 23:59')));
        $this->assertTrue($cycle->isSendDue(CarbonImmutable::parse('2026-10-03 00:00')));
        $this->assertTrue($cycle->isSendDue(CarbonImmutable::parse('2026-10-05')), 'a late run still sends');

        $fixed = $this->schedule->cycleGeneratedOn($this->plan(PricingMode::Fixed, 1), CarbonImmutable::parse('2026-11-01'));
        $this->assertTrue($fixed->isSendDue(CarbonImmutable::parse('2026-11-01')), 'fixed plans may send the day they are generated');
    }

    public function test_a_plan_created_on_its_generation_day_starts_next_month(): void
    {
        $cycle = $this->schedule->nextCycleForPlan($this->plan(PricingMode::Hourly, 3), CarbonImmutable::parse('2026-11-01 08:00'));

        $this->assertSame('2026-12-01', $cycle->generationDate->toDateString());
        $this->assertSame('2026-12-03', $cycle->sendDate->toDateString());
    }

    public function test_december_rolls_into_january_and_bills_december(): void
    {
        $csr = $this->schedule->nextCycleForPlan($this->plan(PricingMode::Hourly, 3), CarbonImmutable::parse('2026-12-15'));
        $this->assertSame(['2027-01-01', '2027-01-03', '2026-12-01', '2026-12-31'], [
            $csr->generationDate->toDateString(),
            $csr->sendDate->toDateString(),
            $csr->periodStart->toDateString(),
            $csr->periodEnd->toDateString(),
        ]);

        $january = $this->schedule->cycleGeneratedOn($this->plan(PricingMode::Fixed, 1), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('2026-12-01', $january->periodStart->toDateString());
    }

    public function test_february_and_the_current_month(): void
    {
        $march = $this->schedule->cycleGeneratedOn($this->plan(PricingMode::Fixed, 1), CarbonImmutable::parse('2027-03-01'));
        $this->assertSame('2027-02-28', $march->periodEnd->toDateString());

        $current = $this->schedule->nextCycleForPlan($this->plan(PricingMode::Fixed, 28, BillingPeriod::CurrentMonth), CarbonImmutable::parse('2027-02-10'));
        $this->assertSame(['2027-02-28', '2027-02-28', '2027-02-01'], [
            $current->generationDate->toDateString(),
            $current->sendDate->toDateString(),
            $current->periodStart->toDateString(),
        ]);
    }

    public function test_the_same_inputs_always_give_the_same_cycle(): void
    {
        $plan = $this->plan(PricingMode::Hourly, 3);
        $today = CarbonImmutable::parse('2026-10-03');

        $first = $this->schedule->nextCycleForPlan($plan, $today);
        $second = $this->schedule->nextCycleForPlan($plan, $today);

        $this->assertEquals($first, $second);
        $this->assertSame('2026-10-03', $today->toDateString(), 'today is never mutated');
    }
}
