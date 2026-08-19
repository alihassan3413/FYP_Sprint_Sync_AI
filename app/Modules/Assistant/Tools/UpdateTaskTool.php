<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\AssigneeResolver;
use App\Modules\Assistant\Support\FuzzyMatcher;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Actions\UpdateTaskAction;
use App\Modules\Tasks\Actions\UpdateTaskStatusAction;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Data\UpdateTaskStatusData;
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Changes an existing task: who holds it, when it is due, which sprint it is in,
 * where it sits on the board. Every field is optional and anything left out keeps
 * its current value, so "assign it to Rana" cannot quietly wipe a due date.
 */
final class UpdateTaskTool implements AssistantTool, ProvidesConfirmationDetails
{
    /** Column and sprint names are short; a loose match here moves work to the wrong place. */
    private const NAME_FLOOR = 55;

    public function __construct(
        private readonly UpdateTaskAction $updateTask,
        private readonly UpdateTaskStatusAction $updateStatus,
        private readonly AssigneeResolver $assigneeResolver,
    ) {}

    public function name(): string
    {
        return 'update_task';
    }

    public function description(): string
    {
        return 'Updates one existing task: assign or reassign it, set or clear its due date, move it into a sprint, '
            .'move it to another board column (including marking it done), or fix its title or description. '
            .'Get task_id from find_tasks first — never guess it, and never call this while find_tasks says '
            .'needs_disambiguation. Only pass the fields that change; everything else is left alone. '
            .'assignee accepts a name or an email address.';
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
                    'description' => 'The task to change, from find_tasks.',
                ],
                'assignee' => [
                    'type' => 'string',
                    'description' => 'Who should hold the task: a name or an email address. Matched loosely against '
                        .'the people on the project. Pass "unassigned" to take it off whoever has it.',
                    'maxLength' => 100,
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'New due date as YYYY-MM-DD, resolved against the current date above. '
                        .'Pass "none" to clear it.',
                ],
                'sprint' => [
                    'type' => 'string',
                    'description' => 'Sprint to move the task into: "current" for the running sprint, "none" for the backlog, '
                        .'or the sprint name.',
                    'maxLength' => 80,
                ],
                'column' => [
                    'type' => 'string',
                    'description' => 'Board column to move the task to, matched loosely by name, e.g. "done", "in progress".',
                    'maxLength' => 60,
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Corrected title. Only when the user asks to rename the task.',
                    'minLength' => 2,
                    'maxLength' => 150,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Replacement description.',
                    'maxLength' => 5000,
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

        $details = [
            'task' => UntrustedText::inline($task->title) ?? 'Task',
            'project' => UntrustedText::inline($task->project->name) ?? 'Project',
        ];

        if (isset($args['assignee'])) {
            $details['assignee'] = $this->isClearing((string) $args['assignee'])
                ? 'Unassign it'
                : 'Assign to '.UntrustedText::inline((string) $args['assignee']);
        }

        if (isset($args['due_date'])) {
            $details['due_date'] = $this->isClearing((string) $args['due_date'])
                ? 'Clear the due date'
                : 'Due '.UntrustedText::inline((string) $args['due_date']);
        }

        if (isset($args['sprint'])) {
            $details['sprint'] = UntrustedText::inline((string) $args['sprint']) ?? '';
        }

        if (isset($args['column'])) {
            $details['column'] = 'Move to '.UntrustedText::inline((string) $args['column']);
        }

        if (isset($args['title'])) {
            $details['new_title'] = UntrustedText::inline((string) $args['title']) ?? '';
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $user = $context->user;

        if ($workspace === null) {
            return ['success' => false, 'error_code' => 'no_workspace', 'error' => 'No active workspace is selected.'];
        }

        $task = $this->resolveTask($args, $context);

        if ($task === null) {
            return [
                'success' => false,
                'error_code' => 'task_not_found',
                'error' => 'That task does not exist or you do not have access to it.',
                'next_step' => 'Use find_tasks to look the task up again, then retry with the task_id it returns.',
            ];
        }

        $changes = [];
        $assignedTo = $task->assigned_to;

        if (isset($args['assignee'])) {
            $outcome = $this->resolveAssignee($task, (string) $args['assignee']);

            if (is_array($outcome)) {
                return $outcome;
            }

            $assignedTo = $outcome?->id;
            $changes[] = $outcome === null
                ? 'unassigned it'
                : "assigned it to {$outcome->name}";
        }

        $dueDate = $task->due_date?->toDateString();

        if (isset($args['due_date'])) {
            if ($this->isClearing((string) $args['due_date'])) {
                $dueDate = null;
                $changes[] = 'cleared the due date';
            } else {
                $parsed = $this->parseDate((string) $args['due_date']);

                if ($parsed === null) {
                    return [
                        'success' => false,
                        'error_code' => 'invalid_due_date',
                        'error' => "\"{$args['due_date']}\" is not a date I can read. Use YYYY-MM-DD.",
                    ];
                }

                $dueDate = $parsed;
                $changes[] = "set the due date to {$parsed}";
            }
        }

        $sprintId = $task->sprint_id;

        if (isset($args['sprint'])) {
            $outcome = $this->resolveSprint($task, (string) $args['sprint']);

            if (is_array($outcome)) {
                return $outcome;
            }

            $sprintId = $outcome?->id;
            $changes[] = $outcome === null
                ? 'moved it to the backlog'
                : "moved it into \"{$outcome->name}\"";
        }

        if (! $user->can('update', $task) && $this->needsEditRights($args)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to change tasks in {$task->project->name}.",
            ];
        }

        $title = isset($args['title']) ? trim((string) $args['title']) : $task->title;
        $description = array_key_exists('description', $args)
            ? (is_string($args['description']) ? trim($args['description']) : null)
            : $task->description;

        if (isset($args['title']) && $title !== $task->title) {
            $changes[] = "renamed it to \"{$title}\"";
        }

        if ($this->needsEditRights($args)) {
            $this->updateTask->handle($task, $user, StoreTaskData::from([
                'title' => $title,
                'description' => $description,
                'assigned_to' => $assignedTo,
                'due_date' => $dueDate,
                'sprint_id' => $sprintId,
            ]));
        }

        if (isset($args['column'])) {
            $outcome = $this->moveToColumn($task, (string) $args['column'], $context);

            if (is_array($outcome)) {
                return $outcome;
            }

            $changes[] = "moved it to {$outcome->name}";
        }

        $task->refresh()->load(['assignee:id,name,email', 'boardColumn:id,name,is_done', 'sprint:id,name']);

        if ($changes === []) {
            return [
                'success' => true,
                'task' => $this->present($task, $context),
                'message' => "Nothing to change on \"{$task->title}\".",
            ];
        }

        return [
            'success' => true,
            'task' => $this->present($task, $context),
            'message' => "Updated \"{$task->title}\": ".$this->sentence($changes).'.',
        ];
    }

    /**
     * Column moves go through the status action so notifications, broadcasts and
     * completion tracking behave exactly as they do on the board.
     *
     * @return BoardColumn|array<string, mixed>
     */
    private function moveToColumn(Task $task, string $columnName, ToolContext $context): BoardColumn|array
    {
        $columns = $task->project->boardColumns()->orderBy('position')->get();
        $ranked = FuzzyMatcher::rank($columnName, $columns, fn (BoardColumn $column) => [$column->name], self::NAME_FLOOR);

        if ($ranked === []) {
            return [
                'success' => false,
                'error_code' => 'column_not_found',
                'error' => "\"{$task->project->name}\" has no column like \"{$columnName}\".",
                'columns' => $columns->map(fn (BoardColumn $column) => UntrustedText::inline($column->name))->all(),
            ];
        }

        $column = $ranked[0]['item'];

        if (! $context->user->can('moveToColumn', [$task, $column])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to move tasks in {$task->project->name}.",
            ];
        }

        try {
            $this->updateStatus->handle($task, $context->user, UpdateTaskStatusData::from([
                'board_column_id' => $column->id,
            ]));
        } catch (Throwable) {
            return [
                'success' => false,
                'error_code' => 'move_failed',
                'error' => "\"{$task->title}\" could not be moved to {$column->name}.",
            ];
        }

        return $column;
    }

    /**
     * @return User|array<string, mixed>|null
     */
    private function resolveAssignee(Task $task, string $assignee): mixed
    {
        if ($this->isClearing($assignee)) {
            return null;
        }

        $resolution = $this->assigneeResolver->resolve($task->project, $assignee);

        return $resolution->isResolved() ? $resolution->user : $resolution->toolPayload();
    }

    /**
     * @return Sprint|array<string, mixed>|null
     */
    private function resolveSprint(Task $task, string $sprint): mixed
    {
        $sprint = trim($sprint);

        if ($this->isClearing($sprint) || mb_strtolower($sprint) === 'backlog') {
            return null;
        }

        if (mb_strtolower($sprint) === 'current') {
            $active = $task->project->sprints()->active()->first();

            if ($active !== null) {
                return $active;
            }

            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => "{$task->project->name} has no running sprint. Start one with manage_sprint, or leave the task in the backlog.",
            ];
        }

        $sprints = $task->project->sprints()->get();
        $ranked = FuzzyMatcher::rank($sprint, $sprints, fn ($candidate) => [$candidate->name], self::NAME_FLOOR);

        if ($ranked === []) {
            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => "\"{$task->project->name}\" has no sprint like \"{$sprint}\".",
                'sprints' => $sprints->map(fn ($candidate) => UntrustedText::inline($candidate->name))->all(),
            ];
        }

        return $ranked[0]['item'];
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
            ->with(['project', 'assignee:id,name,email', 'boardColumn:id,name,is_done', 'sprint:id,name'])
            ->whereIn('project_id', $projectIds)
            ->whereKey((int) $args['task_id'])
            ->first();
    }

    /**
     * Column-only moves are allowed for people who can move but not edit — an
     * assignee dragging their own card, or a client with the close permission.
     *
     * @param  array<string, mixed>  $args
     */
    private function needsEditRights(array $args): bool
    {
        return isset($args['assignee'])
            || isset($args['due_date'])
            || isset($args['sprint'])
            || isset($args['title'])
            || array_key_exists('description', $args);
    }

    private function isClearing(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['none', 'unassigned', 'nobody', 'no one', 'clear', 'remove', ''], true);
    }

    private function parseDate(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function sentence(array $changes): string
    {
        if (count($changes) === 1) {
            return $changes[0];
        }

        $last = array_pop($changes);

        return implode(', ', $changes)." and {$last}";
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Task $task, ToolContext $context): array
    {
        return [
            'task_id' => $task->id,
            'title' => UntrustedText::inline($task->title),
            'project_id' => $task->project_id,
            'project_name' => UntrustedText::inline($task->project->name),
            'assignee_name' => UntrustedText::inline($task->assignee?->name),
            'assignee_email' => UntrustedText::inline($task->assignee?->email),
            'due_date' => $task->due_date?->toDateString(),
            'column' => UntrustedText::inline($task->boardColumn?->name),
            'is_done' => $task->isCompleted(),
            'sprint_name' => UntrustedText::inline($task->sprint?->name),
            'url' => route('workspace.projects.show', [
                'workspace' => $context->workspace->slug,
                'project' => $task->project_id,
            ])."?task={$task->id}",
        ];
    }
}
