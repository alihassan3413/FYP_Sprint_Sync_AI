<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\AssigneeResolver;
use App\Modules\Assistant\Support\FuzzyMatcher;
use App\Modules\Assistant\Support\ProjectResolver;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Data\StoreTaskData;
use App\Modules\Tasks\Models\Task;
use App\UserRole;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Creates a task, working out the missing pieces rather than demanding them:
 * the project is inferred when there is only one, matched by name when the user
 * named one, and asked about only when it is genuinely ambiguous.
 */
final class CreateTaskTool implements AssistantTool, ProvidesConfirmationDetails
{
    /** Above this, an existing task is close enough that we mention it before duplicating work. */
    private const DUPLICATE_SCORE = 80;

    /** Sprint names are short, so they need a stricter bar than task titles. */
    private const NAME_FLOOR = 55;

    public function __construct(
        private readonly CreateTaskAction $action,
        private readonly ProjectResolver $projectResolver,
        private readonly AssigneeResolver $assigneeResolver,
    ) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Creates a task. The project is worked out for you: leave project_id and project_name out and, '
            .'if the user is only on one project, it goes there. Pass project_name when the user named a project '
            .'("in CIG Florida") — it is matched loosely. Only when several projects could be meant will this ask, '
            .'and then you should ask the user which one and call again. '
            .'The task starts in the project\'s first board column. '
            .'assignee accepts a name or an email address and is matched against the people on the project. '
            .'Pass sprint="current" to put it in the running sprint.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short task title.',
                    'minLength' => 2,
                    'maxLength' => 150,
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Project ID from list_projects. Leave out when the user did not name a project.',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'The project as the user said it, when you do not have its ID. Matched loosely.',
                    'maxLength' => 100,
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional longer description of the work.',
                    'maxLength' => 5000,
                ],
                'assignee' => [
                    'type' => 'string',
                    'description' => 'Optional person to assign: a name or an email address. Leave out when the user named nobody.',
                    'maxLength' => 100,
                ],
                'assignee_email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Older way of naming the assignee. Prefer assignee, which also accepts a name.',
                ],
                'due_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'description' => 'Optional due date as YYYY-MM-DD, resolved against the current date above.',
                ],
                'sprint' => [
                    'type' => 'string',
                    'description' => 'Optional sprint: "current" for the running sprint, or a sprint name.',
                    'maxLength' => 80,
                ],
            ],
            'required' => ['title'],
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

        /* Project managers, and clients whose role lets them raise requests. */
        return $workspace->managedProjectsFor($context->user)->exists()
            || $workspace->accessibleProjectsFor($context->user)
                ->get()
                ->contains(fn (Project $project) => $context->user->can('create', [Task::class, $project]));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array
    {
        $resolution = $this->projectResolver->resolve($context, $args, 'this task');

        $details = [
            'task' => UntrustedText::inline((string) ($args['title'] ?? 'Untitled task')) ?? 'Untitled task',
            'project' => $resolution->isResolved()
                ? (UntrustedText::inline($resolution->project->name) ?? 'Unknown project')
                : 'Not decided yet',
        ];

        $namedPerson = (string) ($args['assignee'] ?? $args['assignee_email'] ?? '');

        if ($namedPerson !== '') {
            $details['assignee'] = UntrustedText::inline($namedPerson) ?? '';
        }

        if (isset($args['due_date'])) {
            $details['due_date'] = UntrustedText::inline((string) $args['due_date']) ?? '';
        }

        if (isset($args['sprint'])) {
            $details['sprint'] = UntrustedText::inline((string) $args['sprint']) ?? '';
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

        $title = trim((string) ($args['title'] ?? ''));

        if ($title === '') {
            return [
                'success' => false,
                'error_code' => 'missing_title',
                'error' => 'A task needs a title. Ask the user what the work is.',
            ];
        }

        $resolution = $this->projectResolver->resolve($context, $args, 'this task');

        if (! $resolution->isResolved()) {
            return $resolution->toolPayload();
        }

        $project = $resolution->project;

        if (! $user->can('create', [Task::class, $project])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to create tasks in {$project->name}.",
            ];
        }

        $assignee = null;
        $namedPerson = trim((string) ($args['assignee'] ?? $args['assignee_email'] ?? ''));

        if ($namedPerson !== '') {
            $assigneeResolution = $this->assigneeResolver->resolve($project, $namedPerson);

            if (! $assigneeResolution->isResolved()) {
                return $assigneeResolution->toolPayload();
            }

            $assignee = $assigneeResolution->user;
        }

        $sprint = $this->resolveSprint($args, $project);

        if (is_array($sprint)) {
            return $sprint;
        }

        $dueDate = null;

        if (! empty($args['due_date'])) {
            $dueDate = $this->parseDate((string) $args['due_date']);

            if ($dueDate === null) {
                return [
                    'success' => false,
                    'error_code' => 'invalid_due_date',
                    'error' => "\"{$args['due_date']}\" is not a date I can read. Use YYYY-MM-DD.",
                ];
            }
        }

        $task = $this->action->handle($project, $user, StoreTaskData::from([
            'title' => $title,
            'description' => isset($args['description']) ? trim((string) $args['description']) : null,
            'assigned_to' => $assignee?->id,
            'due_date' => $dueDate,
            'sprint_id' => $sprint?->id,
        ]));

        $result = [
            'success' => true,
            'task' => [
                'id' => $task->id,
                'title' => UntrustedText::inline($task->title),
                'project_id' => $project->id,
                'project_name' => UntrustedText::inline($project->name),
                'assignee_name' => UntrustedText::inline($assignee?->name),
                'due_date' => $task->due_date?->toDateString(),
                'sprint_id' => $task->sprint_id,
                'sprint_name' => UntrustedText::inline($sprint?->name),
            ],
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $project->id,
            ])."?task={$task->id}",
            'message' => "Created \"{$task->title}\" in {$project->name}"
                .($assignee === null ? '' : " and assigned it to {$assignee->name}")
                .($sprint === null ? '.' : ", in sprint \"{$sprint->name}\"."),
        ];

        $duplicate = $this->nearDuplicate($project, $title, $task->id);

        if ($duplicate !== null) {
            $result['similar_existing_task'] = $duplicate;
            $result['next_step'] = 'A very similar task already exists. Mention it to the user in case they meant '
                .'to update that one instead, and offer to delete the new one.';
        }

        return $result;
    }

    /**
     * @return Sprint|array<string, mixed>|null
     */
    private function resolveSprint(array $args, Project $project): mixed
    {
        $sprint = isset($args['sprint']) ? trim((string) $args['sprint']) : '';

        if ($sprint === '' || in_array(mb_strtolower($sprint), ['none', 'backlog'], true)) {
            return null;
        }

        if (mb_strtolower($sprint) === 'current') {
            $active = $project->sprints()->active()->first();

            if ($active !== null) {
                return $active;
            }

            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => "{$project->name} has no running sprint. Start one with manage_sprint, or leave the task in the backlog.",
            ];
        }

        $sprints = $project->sprints()->get();
        $ranked = FuzzyMatcher::rank($sprint, $sprints, fn (Sprint $candidate) => [$candidate->name], self::NAME_FLOOR);

        if ($ranked === []) {
            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => "\"{$project->name}\" has no sprint like \"{$sprint}\".",
                'sprints' => $sprints->map(fn (Sprint $candidate) => UntrustedText::inline($candidate->name))->all(),
            ];
        }

        return $ranked[0]['item'];
    }

    /**
     * An almost-identical open task usually means the user forgot it exists.
     *
     * @return array<string, mixed>|null
     */
    private function nearDuplicate(Project $project, string $title, int $exceptTaskId): ?array
    {
        $existing = $project->tasks()
            ->open()
            ->whereKeyNot($exceptTaskId)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'title']);

        $ranked = FuzzyMatcher::rank($title, $existing, fn (Task $task) => [$task->title], self::DUPLICATE_SCORE);

        if ($ranked === []) {
            return null;
        }

        return [
            'task_id' => $ranked[0]['item']->id,
            'title' => UntrustedText::inline($ranked[0]['item']->title),
            'match_confidence' => $ranked[0]['score'],
        ];
    }

    private function parseDate(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
