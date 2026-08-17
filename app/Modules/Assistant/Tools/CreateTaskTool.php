<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\UserRole;

final class CreateTaskTool implements AssistantTool
{
    public function __construct(private readonly CreateTaskAction $action) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Creates a task inside a project. Call list_projects first to get a real project_id — never guess one. '
            .'The task starts in the project\'s first default board column. '
            .'To assign it, pass the assignee\'s email address; call get_workspace_info with include_members=true '
            .'to look up member emails. Leave assignee_email out when the user did not name an assignee.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the project, obtained from list_projects.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Short task title.',
                    'minLength' => 2,
                    'maxLength' => 150,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional longer description of the work.',
                    'maxLength' => 5000,
                ],
                'assignee_email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Optional email address of the person to assign. Must already have access to the project.',
                ],
                'due_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Optional due date in YYYY-MM-DD format.',
                ],
            ],
            'required' => ['project_id', 'title'],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function authorize(ToolContext $context): bool
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return false;
        }

        if ($workspace->userHasAtLeast($context->user, UserRole::ADMIN)) {
            return true;
        }

        return $workspace->managedProjectsFor($context->user)->exists();
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

        $project = $workspace->accessibleProjectsFor($user)
            ->whereKey((int) $args['project_id'])
            ->first();

        if ($project === null) {
            return [
                'success' => false,
                'error_code' => 'project_not_found',
                'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
            ];
        }

        if (! $user->can('create', [Task::class, $project])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to create tasks in {$project->name}.",
            ];
        }

        $assignee = null;

        if (! empty($args['assignee_email'])) {
            $assignee = $this->resolveAssignee((string) $args['assignee_email'], $project);

            if ($assignee === null) {
                return [
                    'success' => false,
                    'error_code' => 'assignee_not_assignable',
                    'error' => "{$args['assignee_email']} is not a member of {$project->name}, so the task cannot be assigned to them.",
                ];
            }
        }

        $task = $this->action->handle($project, $user, StoreTaskData::from([
            'title' => trim((string) $args['title']),
            'description' => isset($args['description']) ? trim((string) $args['description']) : null,
            'assigned_to' => $assignee?->id,
            'due_date' => $args['due_date'] ?? null,
        ]));

        return [
            'success' => true,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'assignee_name' => $assignee?->name,
                'due_date' => $task->due_date?->toDateString(),
            ],
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ])."?task={$task->id}",
            'message' => $assignee === null
                ? "Created \"{$task->title}\" in {$project->name}."
                : "Created \"{$task->title}\" in {$project->name} and assigned it to {$assignee->name}.",
        ];
    }

    private function resolveAssignee(string $email, Project $project): ?User
    {
        $assignee = User::query()->where('email', $email)->first();

        if ($assignee === null) {
            return null;
        }

        $isAssignable = $project->workspace->userHasAtLeast($assignee, UserRole::ADMIN)
            || $project->hasMember($assignee);

        return $isAssignable ? $assignee : null;
    }
}
