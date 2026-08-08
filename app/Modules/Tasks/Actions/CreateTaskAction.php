<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class CreateTaskAction
{
    public function __construct(
        private readonly ResolveTaskRecipients $resolveTaskRecipients,
    ) {}

    public function handle(Project $project, User $creator, StoreTaskData $data): Task
    {
        $defaultColumnId = $project->boardColumns()
            ->where('is_default', true)
            ->orderBy('position')
            ->value('id');

        $task = $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'board_column_id' => $defaultColumnId,
            'workspace_id' => $project->workspace_id,
        ]);

        $this->notifyAssignment($task, $project, $creator);

        return $task;
    }

    private function notifyAssignment(Task $task, Project $project, User $actor): void
    {
        $recipients = $this->resolveTaskRecipients->handle($task, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new TaskAssignedNotification(
                projectName: $project->name,
                taskTitle: $task->title,
                assignedByName: $actor->name,
                url: route('workspace.projects.show', ['workspace' => $project->workspace->slug, 'project' => $project->id]),
            ));
        } catch (Throwable $e) {
            Log::error('Task assigned notification dispatch failed', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);
        }
    }
}
