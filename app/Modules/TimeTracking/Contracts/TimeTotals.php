<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Contracts;

use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Data\TimeBreakdown;
use App\Modules\TimeTracking\Data\TimeQuery;
use Carbon\CarbonImmutable;

/**
 * Recorded time, in integer seconds, answering separate questions:
 *
 * - worked: tracked + manual + signed adjustments + idle kept as work;
 * - billable: billable work PLUS paid lunch within the allowance (and excess
 *   the owner chose to count). This is what client invoices use;
 * - paid lunch, holiday credit, paid and unpaid leave, paid ("compensated")
 *   time and time over the weekly target, each reported separately.
 *
 * Never billable: unpaid pauses, holidays, leave, non-billable work and
 * lunch excess still waiting for the owner. Voided entries never count. The one place other modules ask for
 * time ("billable time for Person X on Project Y in October"), so nothing
 * else queries time entries directly. It reports time only; it never changes
 * an invoice.
 */
interface TimeTotals
{
    /**
     * Worked seconds; with billableOnly, billable seconds (billable work plus
     * the paid part of billable lunch). Never holidays or leave.
     */
    public function seconds(TimeQuery $query): int;

    /** Everything for one person over business dates $from–$to (inclusive). */
    public function breakdown(Person $person, CarbonImmutable $from, CarbonImmutable $to): TimeBreakdown;

    /**
     * Activity reviews (unusual activity patterns) still waiting for the owner,
     * for these people over business dates $from–$to. Automatic invoices wait
     * while there are any.
     *
     * @param  list<int>  $personIds
     */
    public function openActivityReviews(int $workspaceId, array $personIds, CarbonImmutable $from, CarbonImmutable $to): int;

    /** One week (Monday–Sunday containing $day), with the weekly target in force at its end (or now). */
    public function week(Person $person, CarbonImmutable $day): TimeBreakdown;

    /**
     * Totals grouped by any of: person_id, project_id, task_id, period_start.
     *
     * @param  list<'person_id'|'project_id'|'task_id'|'period_start'>  $by
     * @return list<array<string, int|string|null>> each row has the group columns and `seconds`
     */
    public function grouped(TimeQuery $query, array $by): array;
}
