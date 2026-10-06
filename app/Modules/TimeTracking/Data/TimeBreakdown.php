<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Data;

/**
 * One person's time over a range, each kind kept apart (integer seconds):
 *
 * - worked: real work (tracked, manual, adjustments, idle kept as work)
 * - billable: billable work plus paid lunch (what client invoices use)
 * - lunch_paid: paid lunch (allowance, plus excess the owner chose to count);
 *   part of billable and of paid time, still shown as its own category
 * - lunch_excess_open: lunch past the allowance still waiting for the owner
 * - holiday: paid holiday credit
 * - paid_leave: paid leave credit (unpaid leave is counted in days only)
 * - compensated: paid time = worked + lunch_paid + holiday + paid_leave
 * - outside_schedule: tracked work outside the expected schedule
 * - worked_on_holiday: work done on holiday dates (also inside worked)
 * - target / over_target: weekly target and compensated time above it
 */
final readonly class TimeBreakdown
{
    public function __construct(
        public int $worked = 0,
        public int $billable = 0,
        public int $lunchPaid = 0,
        public int $lunchExcessOpen = 0,
        public int $holiday = 0,
        public int $paidLeave = 0,
        public int $unpaidLeaveDays = 0,
        public int $outsideSchedule = 0,
        public int $workedOnHoliday = 0,
        public ?int $target = null,
    ) {}

    public function compensated(): int
    {
        return $this->worked + $this->lunchPaid + $this->holiday + $this->paidLeave;
    }

    public function overTarget(): int
    {
        return $this->target === null ? 0 : max(0, $this->compensated() - $this->target);
    }

    public function withTarget(?int $target): self
    {
        return new self($this->worked, $this->billable, $this->lunchPaid, $this->lunchExcessOpen, $this->holiday, $this->paidLeave, $this->unpaidLeaveDays, $this->outsideSchedule, $this->workedOnHoliday, $target);
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'worked' => $this->worked,
            'billable' => $this->billable,
            'lunch_paid' => $this->lunchPaid,
            'lunch_excess_open' => $this->lunchExcessOpen,
            'holiday' => $this->holiday,
            'paid_leave' => $this->paidLeave,
            'unpaid_leave_days' => $this->unpaidLeaveDays,
            'compensated' => $this->compensated(),
            'outside_schedule' => $this->outsideSchedule,
            'worked_on_holiday' => $this->workedOnHoliday,
            'target' => $this->target,
            'over_target' => $this->overTarget(),
        ];
    }
}
