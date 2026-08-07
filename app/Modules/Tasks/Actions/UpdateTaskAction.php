<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;

final class UpdateTaskAction
{
    public function handle(Task $task, StoreTaskData $data): Task
    {
        $task->update([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
        ]);

        return $task->refresh();
    }
}
