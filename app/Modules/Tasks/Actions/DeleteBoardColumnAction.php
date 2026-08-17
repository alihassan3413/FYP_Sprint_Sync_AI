<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Tasks\Models\BoardColumn;

final class DeleteBoardColumnAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(BoardColumn $column, User $actor): void
    {
        $project = $column->project;
        $name = $column->name;

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::BOARD_COLUMN_DELETED,
            "{$actor->name} deleted column \"{$name}\".",
            $column,
        );

        $column->delete();
    }
}
