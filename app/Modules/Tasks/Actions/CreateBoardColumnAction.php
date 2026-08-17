<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\BoardColumn;

final class CreateBoardColumnAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Project $project, string $name, User $actor): BoardColumn
    {
        $position = (int) $project->boardColumns()->max('position') + 1;

        $column = $project->boardColumns()->create([
            'name' => $name,
            'position' => $position,
            'is_default' => false,
            'is_done' => false,
        ]);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::BOARD_COLUMN_CREATED,
            "{$actor->name} created column \"{$column->name}\".",
            $column,
        );

        return $column;
    }
}
