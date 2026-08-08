<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class UpdateTaskAction
{
    public function __construct(
        private readonly ResolveTaskRecipients $resolveTaskRecipients,
        private readonly NotificationPreferenceGate $preferences,
    ) {}

    public function handle(Task $task, User $actor, StoreTaskData $data): Task
    {
        $task->update([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
        ]);

        if ($task->wasChanged('assigned_to')) {
            $this->notifyAssignment($task, $actor);
        }

        return $task->refresh();
    }

    private function notifyAssignment(Task $task, User $actor): void
    {
        $recipients = $this->resolveTaskRecipients->handle($task, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            $recipients = $this->preferences->filter($recipients, NotificationType::TASK_ASSIGNED, NotificationChannel::IN_APP);

            Notification::send($recipients, new TaskAssignedNotification(
                projectName: $task->project->name,
                taskTitle: $task->title,
                assignedByName: $actor->name,
                url: route('workspace.projects.show', ['workspace' => $task->project->workspace->slug, 'project' => $task->project_id]),
            ));
        } catch (Throwable $e) {
            Log::error('Task assigned notification dispatch failed', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);
        }
    }
}
