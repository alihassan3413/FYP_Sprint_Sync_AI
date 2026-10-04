<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Services;

use App\Modules\TimeTracking\Contracts\TimeTotals;
use App\Modules\TimeTracking\Data\TimeQuery;
use App\Modules\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class EntryTimeTotals implements TimeTotals
{
    private const GROUPS = ['person_id', 'project_id', 'task_id', 'period_start'];

    public function seconds(TimeQuery $query): int
    {
        return (int) $this->filtered($query)->sum('duration_seconds');
    }

    public function grouped(TimeQuery $query, array $by): array
    {
        if ($by === [] || array_diff($by, self::GROUPS) !== []) {
            throw new InvalidArgumentException('Group time by person_id, project_id, task_id or period_start.');
        }

        return $this->filtered($query)
            ->select($by)
            ->selectRaw('SUM(duration_seconds) as seconds')
            ->groupBy($by)
            ->orderBy($by[0])
            ->toBase()
            ->get()
            ->map(function (object $row) {
                $row = (array) $row;
                $row['seconds'] = (int) $row['seconds'];

                if (isset($row['period_start'])) {
                    $row['period_start'] = substr((string) $row['period_start'], 0, 10);
                }

                return $row;
            })
            ->all();
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function filtered(TimeQuery $query): Builder
    {
        return TimeEntry::query()
            ->active()
            ->where('workspace_id', $query->workspaceId)
            ->when($query->personId, fn (Builder $q) => $q->where('person_id', $query->personId))
            ->when($query->projectId, fn (Builder $q) => $q->where('project_id', $query->projectId))
            ->when($query->taskId, fn (Builder $q) => $q->where('task_id', $query->taskId))
            ->when($query->from, fn (Builder $q) => $q->whereDate('period_start', '>=', $query->from->toDateString()))
            ->when($query->to, fn (Builder $q) => $q->whereDate('period_start', '<=', $query->to->toDateString()))
            ->when($query->billableOnly, fn (Builder $q) => $q->where('billable', true));
    }
}
