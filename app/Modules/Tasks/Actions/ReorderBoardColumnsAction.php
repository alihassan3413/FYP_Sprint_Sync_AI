<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

final class ReorderBoardColumnsAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    /**
     * @param  array<int, int>  $orderedColumnIds
     */
    public function handle(Project $project, array $orderedColumnIds, User $actor): void
    {
        DB::transaction(function () use ($project, $orderedColumnIds) {
            foreach ($orderedColumnIds as $position => $columnId) {
                $project->boardColumns()->whereKey($columnId)->update(['position' => $position]);
            }
        });

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::BOARD_COLUMN_REORDERED,
            "{$actor->name} reordered board columns.",
            null,
            ['ordered_column_ids' => $orderedColumnIds],
        );
    }
}
