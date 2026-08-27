<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Models\User;
use App\Modules\Attachments\Actions\ClaimAttachmentsAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationPreferenceGate;
use App\Notifications\NotificationType;
use App\Notifications\TaskCommentPostedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

final class CreateTaskCommentAction
{
    public function __construct(
        private readonly ResolveTaskRecipients $resolveTaskRecipients,
        private readonly NotificationPreferenceGate $preferences,
        private readonly ClaimAttachmentsAction $claimAttachments,
    ) {}

    /**
     * @param  array<int, int|string>  $attachmentIds
     */
    public function handle(Task $task, User $author, string $body, array $attachmentIds = []): TaskComment
    {
        $comment = $task->comments()->create([
            'body' => $body,
            'user_id' => $author->id,
        ]);

        if ($attachmentIds !== []) {
            $this->claimAttachments->handle(
                $comment,
                $author,
                $attachmentIds,
                (int) config('attachments.max_per_comment'),
                $task->workspace_id,
            );

            $comment->load('attachments');
        }

        $this->notifyComment($task, $author, $body);

        return $comment;
    }

    private function notifyComment(Task $task, User $author, string $body): void
    {
        $recipients = $this->resolveTaskRecipients->handle($task, $author);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            $recipients = $this->preferences->filter($recipients, NotificationType::TASK_COMMENT, NotificationChannel::IN_APP);

            Notification::send($recipients, new TaskCommentPostedNotification(
                projectName: $task->project->name,
                taskTitle: $task->title,
                commenterName: $author->name,
                excerpt: Str::limit($body, 80),
                url: route('workspace.projects.show', ['workspace' => $task->project->workspace->slug, 'project' => $task->project_id]),
            ));
        } catch (Throwable $e) {
            Log::error('Task comment notification dispatch failed', [
                'task_id' => $task->id,
                'exception' => $e,
            ]);
        }
    }
}
