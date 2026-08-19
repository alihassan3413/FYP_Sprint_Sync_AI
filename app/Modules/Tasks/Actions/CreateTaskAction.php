<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class CreateTaskAction
{
    public function __construct(
        private readonly ResolveTaskRecipients $resolveTaskRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Project $project, User $creator, StoreTaskData $data): Task
    {
        $defaultColumn = $project->boardColumns()
            ->where('is_default', true)
            ->orderBy('position')
            ->first();

        $defaultColumnId = $defaultColumn?->id;

        $task = $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'sprint_id' => $data->sprint_id,
            'board_column_id' => $defaultColumnId,
            'completed_at' => $defaultColumn?->is_done === true ? now() : null,
            'workspace_id' => $project->workspace_id,
        ]);

        $this->auditLogger->handle(
            $project->workspace,
            $project,
            $creator,
            AuditAction::TASK_CREATED,
            "{$creator->name} created \"{$task->title}\".",
            $task,
        );

        if ($task->assigned_to !== null) {
            $this->auditLogger->handle(
                $project->workspace,
                $project,
                $creator,
                AuditAction::TASK_ASSIGNED,
                "{$creator->name} assigned \"{$task->title}\" to {$task->assignee->name}.",
                $task,
            );
        }

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
            $recipients = $this->preferences->filter($recipients, NotificationType::TASK_ASSIGNED, NotificationChannel::IN_APP);

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
