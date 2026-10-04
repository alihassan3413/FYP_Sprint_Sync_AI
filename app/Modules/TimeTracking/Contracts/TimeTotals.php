<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Contracts;

use App\Modules\TimeTracking\Data\TimeQuery;

/**
 * Effective recorded time, in integer seconds: tracked + manual + signed
 * adjustments, excluding voided entries. The one place other modules ask for
 * time ("billable time for Person X on Project Y in October"), so nothing
 * else queries time entries directly. It reports time only; it never changes
 * an invoice.
 */
interface TimeTotals
{
    public function seconds(TimeQuery $query): int;

    /**
     * Totals grouped by any of: person_id, project_id, task_id, period_start.
     *
     * @param  list<'person_id'|'project_id'|'task_id'|'period_start'>  $by
     * @return list<array<string, int|string|null>> each row has the group columns and `seconds`
     */
    public function grouped(TimeQuery $query, array $by): array;
}
