<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Attachments\Actions\ClaimAttachmentsAction;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\BoardColumn;
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
        private readonly ClaimAttachmentsAction $claimAttachments,
    ) {}

    public function handle(Project $project, User $creator, StoreTaskData $data): Task
    {
        $column = $this->resolveColumn($project, $data->board_column_id);

        $task = $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'sprint_id' => $data->sprint_id,
            'board_column_id' => $column?->id,
            'completed_at' => $column?->is_done === true ? now() : null,
            'workspace_id' => $project->workspace_id,
        ]);

        if ($data->attachment_ids !== []) {
            $this->claimAttachments->handle(
                $task,
                $creator,
                $data->attachment_ids,
                (int) config('attachments.max_per_task'),
                $project->workspace_id,
            );

            $task->load('attachments');
        }

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

    /**
     * The caller may name a column; anything else starts where new work starts.
     * An unknown or foreign column id falls back rather than throwing, because
     * a task landing in the wrong column is recoverable and losing it is not.
     */
    private function resolveColumn(Project $project, ?int $columnId): ?BoardColumn
    {
        if ($columnId !== null) {
            $chosen = $project->boardColumns()->whereKey($columnId)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $project->boardColumns()
            ->where('is_default', true)
            ->orderBy('position')
            ->first();
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
