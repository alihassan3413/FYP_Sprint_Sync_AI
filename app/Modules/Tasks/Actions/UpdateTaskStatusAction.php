<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\UpdateTaskStatusData;
use App\Modules\Tasks\Models\Task;

final class UpdateTaskStatusAction
{
    public function handle(Task $task, UpdateTaskStatusData $data): Task
    {
        $task->update(['status' => $data->status]);

        return $task->refresh();
    }
}
