<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class ReorderBoardColumnsAction
{
    /**
     * @param  array<int, int>  $orderedColumnIds
     */
    public function handle(Project $project, array $orderedColumnIds): void
    {
        DB::transaction(function () use ($project, $orderedColumnIds) {
            foreach ($orderedColumnIds as $position => $columnId) {
                $project->boardColumns()->whereKey($columnId)->update(['position' => $position]);
            }
        });
    }
}
