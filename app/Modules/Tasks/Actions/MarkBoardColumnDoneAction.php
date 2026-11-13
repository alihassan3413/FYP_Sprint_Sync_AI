<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Tasks\Models\BoardColumn;
use Illuminate\Support\Facades\DB;

/**
 * Turns a custom list into a "done" list (or back). Tasks already in it follow
 * the same rule as moving a task: done lists stamp completed_at once, keeping
 * an earlier completion date; other lists clear it.
 */
final class MarkBoardColumnDoneAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(BoardColumn $column, bool $isDone, User $actor): BoardColumn
    {
        if ($column->is_done === $isDone) {
            return $column;
        }

        DB::transaction(function () use ($column, $isDone): void {
            $column->update(['is_done' => $isDone]);

            $isDone
                ? $column->tasks()->whereNull('completed_at')->update(['completed_at' => now()])
                : $column->tasks()->update(['completed_at' => null]);
        });

        $this->auditLogger->handle(
            $column->project->workspace,
            $column->project,
            $actor,
            AuditAction::BOARD_COLUMN_UPDATED,
            $isDone
                ? "{$actor->name} marked list \"{$column->name}\" as done."
                : "{$actor->name} marked list \"{$column->name}\" as not done.",
            $column,
        );

        return $column;
    }
}
