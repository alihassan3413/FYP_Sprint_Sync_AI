<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Tasks\Actions\DeleteTaskAction;
use App\Modules\Tasks\Models\Task;

/**
 * Deletes a task for good. Separate from update_task on purpose: deleting and
 * marking something done are different things, and the assistant must never
 * quietly swap one for the other.
 */
final class DeleteTaskTool implements AssistantTool, ProvidesConfirmationDetails
{
    public function __construct(private readonly DeleteTaskAction $action) {}

    public function name(): string
    {
        return 'delete_task';
    }

    public function description(): string
    {
        return 'Permanently deletes a task and its comments. Use this only when the user actually asks to delete, '
            .'remove or get rid of a task — marking something done is update_task with a done column, not this. '
            .'Get task_id from find_tasks first and never guess it. If find_tasks reports needs_disambiguation, '
            .'ask the user which task they mean before calling this. Deleting cannot be undone.';
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
                    'description' => 'The task to delete, taken from find_tasks.',
                ],
            ],
            'required' => ['task_id'],
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

        if ($task === null) {
            return ['task' => 'Unknown task'];
        }

        $comments = $task->comments()->count();

        return [
            'task' => UntrustedText::inline($task->title) ?? 'Task',
            'project' => UntrustedText::inline($task->project->name) ?? 'Project',
            'column' => UntrustedText::inline($task->boardColumn?->name) ?? '—',
            'also_deletes' => $comments === 1 ? '1 comment' : "{$comments} comments",
            'warning' => 'This permanently deletes the task. It cannot be undone.',
        ];
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
                'error' => 'That task does not exist or you do not have access to it. It may already have been deleted.',
                'next_step' => 'Use find_tasks to check whether the task is still there before trying again.',
            ];
        }

        if (! $context->user->can('delete', $task)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to delete tasks in {$task->project->name}.",
            ];
        }

        $title = $task->title;
        $projectName = $task->project->name;

        $this->action->handle($task, $context->user);

        return [
            'success' => true,
            'deleted_task' => [
                'task_id' => $task->id,
                'title' => UntrustedText::inline($title),
                'project_name' => UntrustedText::inline($projectName),
            ],
            'message' => "Deleted \"{$title}\" from {$projectName}.",
        ];
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
            ->with(['project', 'boardColumn:id,name'])
            ->whereIn('project_id', $projectIds)
            ->whereKey((int) $args['task_id'])
            ->first();
    }
}
