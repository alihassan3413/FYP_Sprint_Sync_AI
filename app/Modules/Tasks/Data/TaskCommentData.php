<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\Modules\Tasks\Models\TaskComment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TaskCommentData extends Data
{
    public function __construct(
        public int $id,
        public string $body,
        public int $task_id,
        public int $user_id,
        public string $user_name,
        public string $created_at,
    ) {}

    public static function fromModel(TaskComment $comment): self
    {
        return new self(
            id: $comment->id,
            body: $comment->body,
            task_id: $comment->task_id,
            user_id: $comment->user_id,
            user_name: $comment->user->name,
            created_at: $comment->created_at->toIso8601String(),
        );
    }
}
