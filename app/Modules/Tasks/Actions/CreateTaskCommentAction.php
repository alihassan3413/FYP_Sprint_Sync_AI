<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;

final class CreateTaskCommentAction
{
    public function handle(Task $task, User $author, string $body): TaskComment
    {
        return $task->comments()->create([
            'body' => $body,
            'user_id' => $author->id,
        ]);
    }
}
