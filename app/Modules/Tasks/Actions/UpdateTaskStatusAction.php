<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Tasks\Data\UpdateTaskStatusData;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Notifications\TaskMovedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class UpdateTaskStatusAction
{
    public function __construct(
        private readonly ResolveTaskRecipients $resolveTaskRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(Task $task, User $actor, UpdateTaskStatusData $data): Task
    {
        $previousColumnId = $task->board_column_id;

        $task->update(['board_column_id' => $data->board_column_id]);

        if ($task->wasChanged('board_column_id')) {
            $previousColumnName = BoardColumn::query()->whereKey($previousColumnId)->value('name') ?? 'Unknown';
            $newColumnName = $task->boardColumn->name;

            $this->auditLogger->handle(
                $task->project->workspace,
                $task->project,
                $actor,
                AuditAction::TASK_MOVED,
                "{$actor->name} moved \"{$task->title}\" from {$previousColumnName} to {$newColumnName}.",
                $task,
            );

            $this->notifyMove($task, $actor);
        }

        return $task->refresh();
    }

    private function notifyMove(Task $task, User $actor): void
    {
        $recipients = $this->resolveTaskRecipients->handle($task, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            $recipients = $this->preferences->filter($recipients, NotificationType::TASK_MOVED, NotificationChannel::IN_APP);

            Notification::send($recipients, new TaskMovedNotification(
                projectName: $task->project->name,
                taskTitle: $task->title,
                columnName: $task->boardColumn->name,
                movedByName: $actor->name,
                url: route('workspace.projects.show', ['workspace' => $task->project->workspace->slug, 'project' => $task->project_id]),
            ));
        } catch (Throwable $e) {
            Log::error('Task moved notification dispatch failed', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);
        }
    }
}
