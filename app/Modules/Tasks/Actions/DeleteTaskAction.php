<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\Task;

final class DeleteTaskAction
{
    public function handle(Task $task): void
    {
        $task->delete();
    }
}
