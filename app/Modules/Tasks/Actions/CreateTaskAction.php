<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;

final class CreateTaskAction
{
    public function handle(Project $project, StoreTaskData $data): Task
    {
        $defaultColumnId = $project->boardColumns()
            ->where('is_default', true)
            ->orderBy('position')
            ->value('id');

        return $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'board_column_id' => $defaultColumnId,
            'workspace_id' => $project->workspace_id,
        ]);
    }
}
