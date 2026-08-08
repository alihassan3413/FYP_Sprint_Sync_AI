<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\TaskComment;

final class DeleteTaskCommentAction
{
    public function handle(TaskComment $comment): void
    {
        $comment->delete();
    }
}
