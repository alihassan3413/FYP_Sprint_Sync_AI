<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Tasks\Models\Task;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class TaskData extends Data
{
    /**
     * @param  array<int, TaskCommentData>  $comments
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public int $board_column_id,
        public ?string $due_date,
        public int $project_id,
        public ?int $sprint_id,
        public int $workspace_id,
        public ?int $assigned_to,
        public ?string $assignee_name,
        public array $comments,
        public string $created_at,
        public string $updated_at,
        #[TypeScriptType('Array<{ id: number; name: string; mime: string; size: number; width: number | null; height: number | null; url: string; is_image: boolean }>')]
        public array $attachments = [],
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
            sprint_id: $task->sprint_id,
            workspace_id: $task->workspace_id,
            assigned_to: $task->assigned_to,
            assignee_name: $task->assignee?->name,
            comments: $task->comments
                ->sortBy('created_at')
                ->map(TaskCommentData::fromModel(...))
                ->values()
                ->all(),
            created_at: $task->created_at->toIso8601String(),
            updated_at: $task->updated_at->toIso8601String(),
            attachments: $task->relationLoaded('attachments')
                ? $task->attachments->map(fn (Attachment $attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->name,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                    'width' => $attachment->width,
                    'height' => $attachment->height,
                    'url' => route('attachments.show', $attachment),
                    'is_image' => $attachment->isImage(),
                ])->values()->all()
                : [],
        );
    }
}
