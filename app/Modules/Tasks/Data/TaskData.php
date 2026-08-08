<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\Task;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TaskData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public int $board_column_id,
        public ?string $due_date,
        public int $project_id,
        public int $workspace_id,
        public ?int $assigned_to,
        public ?string $assignee_name,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Task $task): self
    {
        return new self(
            id: $task->id,
            title: $task->title,
            description: $task->description,
            board_column_id: $task->board_column_id,
            due_date: $task->due_date?->toDateString(),
            project_id: $task->project_id,
            workspace_id: $task->workspace_id,
            assigned_to: $task->assigned_to,
            assignee_name: $task->assignee?->name,
            created_at: $task->created_at->toIso8601String(),
            updated_at: $task->updated_at->toIso8601String(),
        );
    }
}
