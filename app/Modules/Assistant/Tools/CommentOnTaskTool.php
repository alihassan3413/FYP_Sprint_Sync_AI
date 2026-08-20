<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Tasks\Actions\CreateTaskCommentAction;
use App\Modules\Tasks\Data\TaskCommentData;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CommentOnTaskTool implements AssistantTool, ProvidesConfirmationDetails
{
    public function __construct(private readonly CreateTaskCommentAction $action) {}

    public function name(): string
    {
        return 'comment_on_task';
    }

    public function description(): string
    {
        return 'Posts a comment on a task, in the user\'s own name, visible to everyone who can see the task. '
            .'Use this when the user asks to comment on, reply to, note something on, or leave an update on a task. '
            .'Get task_id from find_tasks first and never guess it; if find_tasks reports needs_disambiguation, ask '
            .'which task they mean before calling this. Write body as the user\'s own words — do not add your own '
            .'commentary, a signature, or a note that it came from an assistant. This does not change the task '
            .'itself: use update_task to reassign it, move it, or set a due date.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'The task to comment on, taken from find_tasks.',
                ],
                'body' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => TaskCommentData::BODY_MAX_LENGTH,
                    'description' => 'The comment text, in the user\'s own words.',
                ],
            ],
            'required' => ['task_id', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array
    {
        $task = $this->resolveTask($args, $context);

        $details = [
            'project' => $task === null
                ? 'Unknown project'
                : (UntrustedText::inline($task->project->name) ?? 'Project'),
            'task' => $task === null
                ? 'Unknown task'
                : (UntrustedText::inline($task->title) ?? 'Task'),
            'comment' => UntrustedText::block(
                $this->bodyFrom($args),
                TaskCommentData::BODY_MAX_LENGTH,
            ) ?? '',
            'posting_as' => UntrustedText::inline($context->user->name) ?? 'You',
        ];

        $details['visible_to'] = 'Everyone who can see this task.';

        return $details;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return ['success' => false, 'error_code' => 'no_workspace', 'error' => 'No active workspace is selected.'];
        }

        $task = $this->resolveTask($args, $context);

        if ($task === null) {
            return [
                'success' => false,
                'error_code' => 'task_not_found',
                'error' => 'That task does not exist or you do not have access to it.',
                'next_step' => 'Use find_tasks to check the task is still there before trying again.',
            ];
        }

        try {
            $body = $this->validatedBody($args);
        } catch (ValidationException) {
            return [
                'success' => false,
                'error_code' => 'invalid_body',
                'error' => 'A comment needs some text, and cannot be longer than '
                    .TaskCommentData::BODY_MAX_LENGTH.' characters.',
            ];
        }

        if (! $context->user->can('create', [TaskComment::class, $task])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => 'You do not have permission to comment on that task.',
            ];
        }

        $comment = $this->action->handle($task, $context->user, $body);

        return [
            'success' => true,
            'comment' => [
                'comment_id' => $comment->id,
                'task_id' => $task->id,
                'task_title' => UntrustedText::inline($task->title),
                'project_name' => UntrustedText::inline($task->project->name),
            ],
            'message' => 'Comment posted on "'.$task->title.'".',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function bodyFrom(array $args): string
    {
        return trim((string) ($args['body'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws ValidationException
     */
    private function validatedBody(array $args): string
    {
        $validated = Validator::make(
            ['body' => $this->bodyFrom($args)],
            ['body' => TaskCommentData::bodyRules()],
        )->validate();

        return (string) $validated['body'];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveTask(array $args, ToolContext $context): ?Task
    {
        $workspace = $context->workspace;

        if ($workspace === null || ! isset($args['task_id'])) {
            return null;
        }

        $projectIds = $workspace->accessibleProjectsFor($context->user)->pluck('id');

        return Task::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->whereKey((int) $args['task_id'])
            ->first();
    }
}
