<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\TaskStatus;

final class CreateTaskAction
{
    public function handle(Project $project, StoreTaskData $data): Task
    {
        return $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'status' => TaskStatus::TODO,
            'workspace_id' => $project->workspace_id,
        ]);
    }
}
