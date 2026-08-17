<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Tasks\Models\Task;

final class DeleteTaskAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Task $task, User $actor): void
    {
        $project = $task->project;
        $title = $task->title;

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $actor,
            AuditAction::TASK_DELETED,
            "{$actor->name} deleted \"{$title}\".",
            $task,
        );

        $task->delete();
    }
}
