<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Data;

use App\Modules\Attachments\Models\Attachment;
use App\Modules\Tasks\Models\TaskComment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[TypeScript]
final class TaskCommentData extends Data
{
    public const BODY_MAX_LENGTH = 2000;

    /**
     * @return array<int, string>
     */
    public static function bodyRules(): array
    {
        return ['required', 'string', 'min:1', 'max:'.self::BODY_MAX_LENGTH];
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function __construct(
        public int $id,
        public string $body,
        public int $task_id,
        public int $user_id,
        public string $user_name,
        public string $created_at,
        #[TypeScriptType('Array<{ id: number; name: string; mime: string; size: number; width: number | null; height: number | null; url: string; is_image: boolean }>')]
        public array $attachments = [],
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
            attachments: $comment->relationLoaded('attachments')
                ? $comment->attachments->map(fn (Attachment $attachment) => [
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
