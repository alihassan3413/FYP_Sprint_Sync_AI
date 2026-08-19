<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
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
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Task $task, User $actor, StoreTaskData $data): Task
    {
        $previousAssigneeId = $task->assigned_to;

        $task->update([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'sprint_id' => $data->sprint_id,
        ]);

        if ($task->wasChanged(['title', 'description', 'due_date'])) {
            $this->auditLogger->handle(
                $task->project->workspace,
                $task->project,
                $actor,
                AuditAction::TASK_UPDATED,
                "{$actor->name} updated \"{$task->title}\".",
                $task,
                ['changed_fields' => array_keys($task->getChanges())],
            );
        }

        if ($task->wasChanged('assigned_to')) {
            $this->auditAssignment($task, $actor, $previousAssigneeId);
            $this->notifyAssignment($task, $actor);
        }

        return $task->refresh();
    }

    private function auditAssignment(Task $task, User $actor, ?int $previousAssigneeId): void
    {
        $previousAssigneeName = $previousAssigneeId !== null
            ? User::query()->whereKey($previousAssigneeId)->value('name')
            : null;
        $newAssigneeName = $task->assignee?->name;

        $description = match (true) {
            $newAssigneeName === null => "{$actor->name} unassigned \"{$task->title}\".",
            $previousAssigneeName === null => "{$actor->name} assigned \"{$task->title}\" to {$newAssigneeName}.",
            default => "{$actor->name} reassigned \"{$task->title}\" from {$previousAssigneeName} to {$newAssigneeName}.",
        };

        $this->auditLogger->handle(
            $task->project->workspace,
            $task->project,
            $actor,
            AuditAction::TASK_ASSIGNED,
            $description,
            $task,
        );
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
