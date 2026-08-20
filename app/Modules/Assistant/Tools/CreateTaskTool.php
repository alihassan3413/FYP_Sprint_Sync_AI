<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\DefersConfirmation;
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
use App\Modules\Tasks\Models\BoardColumn;
use App\Modules\Tasks\Models\Task;
use App\UserRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Creates a task, working out the missing pieces rather than demanding them:
 * the project is inferred when there is only one, matched by name when the user
 * named one, and asked about only when it is genuinely ambiguous.
 */
final class CreateTaskTool implements AssistantTool, DefersConfirmation, ProvidesConfirmationDetails
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
            .'Once the project is known, this asks which list on the board the task should start in, and — when that '
            .'project has a sprint running — whether the task belongs in that sprint. Both come back as questions '
            .'with the options listed: put them to the user, then call again with board_list and sprint set. '
            .'assignee accepts a name or an email address and is matched against the people on the project.';
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
                'board_list' => [
                    'type' => 'string',
                    'description' => 'Which list on the board the task starts in, as the user said it ("In Progress"). '
                        .'Users call these lists, and some say columns — either is fine. Matched loosely against that '
                        .'project\'s lists. Use "default" for wherever new work normally starts.',
                    'maxLength' => 80,
                ],
                'board_column' => [
                    'type' => 'string',
                    'description' => 'Older name for board_list. Prefer board_list.',
                    'maxLength' => 80,
                ],
                'board_list_id' => [
                    'type' => 'integer',
                    'description' => 'The list ID, when this tool has already handed you the options.',
                ],
                'board_column_id' => [
                    'type' => 'integer',
                    'description' => 'Older name for board_list_id. Prefer board_list_id.',
                ],
                'sprint' => [
                    'type' => 'string',
                    'description' => 'Which sprint, once the user has said: "current" for the running sprint, '
                        .'"none" to leave it in the backlog, or a sprint name.',
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
     * Placement is asked about before anything is confirmed, so the user answers
     * a question rather than approving a card that then reports a failure.
     *
     * @param  array<string, mixed>  $args
     */
    public function needsMoreInformation(array $args, ToolContext $context): bool
    {
        return $this->pendingQuestion($args, $context) !== null;
    }

    /**
     * The next thing this tool has to ask before it can create anything, or null
     * when it has everything it needs. Shared with execute() so the question the
     * user is asked is always the question the tool would actually raise.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function pendingQuestion(array $args, ToolContext $context): ?array
    {
        $workspace = $context->workspace;

        if ($workspace === null) {
            return null;
        }

        $resolution = $this->projectResolver->resolve($context, $args, 'this task');

        if (! $resolution->isResolved()) {
            return $resolution->toolPayload();
        }

        /* A client's request lands in the starting column; they are never asked. */
        if ($workspace->isClient($context->user)) {
            return null;
        }

        $column = $this->resolveColumn($args, $resolution->project);

        if (is_array($column)) {
            return $column;
        }

        return $this->askAboutSprint($args, $resolution->project);
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

        if ($this->namedList($args) !== '') {
            $details['list'] = UntrustedText::inline((string) $this->namedList($args)) ?? '';
        }

        if (isset($args['sprint'])) {
            $sprint = trim((string) $args['sprint']);
            $details['sprint'] = in_array(mb_strtolower($sprint), ['none', 'backlog'], true)
                ? 'Backlog (not in a sprint)'
                : (UntrustedText::inline($sprint) ?? '');
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

        $isClient = $workspace->isClient($user);
        $namedPerson = trim((string) ($args['assignee'] ?? $args['assignee_email'] ?? ''));

        /*
         * The same rule the web form enforces: a client raises a request, the team
         * decides who picks it up and which sprint it lands in.
         */
        if ($isClient && ($namedPerson !== '' || ! empty($args['sprint']))) {
            return [
                'success' => false,
                'error_code' => 'client_cannot_plan_work',
                'error' => 'Clients can request work but cannot assign it to someone or put it in a sprint. '
                    .'The request goes to the team to triage.',
                'next_step' => 'Create the task again without an assignee or sprint, and tell the user the team will pick it up.',
            ];
        }

        $assignee = null;

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

        /*
         * Placement questions come last, after everything the user actually said
         * has been checked. Asking "which column?" and only then reporting that
         * the assignee is not on the project wastes a turn and reads as careless.
         *
         * A client never sees these: their request lands in the starting column
         * for the team to triage, and they cannot plan sprints.
         */
        $column = null;

        if (! $isClient) {
            $column = $this->resolveColumn($args, $project);

            if (is_array($column)) {
                return $column;
            }

            $sprintChoice = $this->askAboutSprint($args, $project);

            if ($sprintChoice !== null) {
                return $sprintChoice;
            }
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
            'board_column_id' => $column?->id,
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
                'board_list' => UntrustedText::inline($task->boardColumn?->name),
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
     * Which column the task starts in.
     *
     * Returns the column, or a tool payload asking the user to pick one. A
     * project with a single column is not worth a question, and neither is one
     * where the user already said where it goes.
     *
     * @param  array<string, mixed>  $args
     * @return BoardColumn|array<string, mixed>|null
     */
    private function resolveColumn(array $args, Project $project): mixed
    {
        $columns = $project->boardColumns()->orderBy('position')->get();

        if ($columns->isEmpty()) {
            return null;
        }

        $chosenId = $args['board_list_id'] ?? $args['board_column_id'] ?? null;

        if ($chosenId !== null) {
            $chosen = $columns->firstWhere('id', (int) $chosenId);

            if ($chosen !== null) {
                return $chosen;
            }
        }

        $named = $this->namedList($args);

        if ($named !== '') {
            if (in_array(mb_strtolower($named), ['default', 'first', 'backlog'], true)) {
                return $this->startingColumn($columns);
            }

            $ranked = FuzzyMatcher::rank($named, $columns, fn (BoardColumn $column) => [$column->name], self::NAME_FLOOR);

            if ($ranked !== []) {
                return $ranked[0]['item'];
            }

            return [
                'success' => false,
                'error_code' => 'list_not_found',
                'awaiting_input' => true,
                'error' => "\"{$project->name}\" has no list like \"{$named}\".",
                'lists' => $this->summariseColumns($columns),
                'next_step' => 'Show the user these lists and ask which one, then call again with board_list_id.',
            ];
        }

        if ($columns->count() === 1) {
            return $columns->first();
        }

        return [
            'success' => false,
            'error_code' => 'list_required',
            'awaiting_input' => true,
            'error' => "Which list should this start in on {$project->name}?",
            'lists' => $this->summariseColumns($columns),
            'next_step' => 'Ask the user which of these lists the task should go in, listing them in order. '
                .'Then call create_task again with the same details plus board_list_id. '
                .'If they do not mind, use the one marked is_starting_list.',
        ];
    }

    /**
     * A running sprint is a decision the user should make knowingly: work put in
     * it is committed scope, and work left out of it is not.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    private function askAboutSprint(array $args, Project $project): ?array
    {
        if (isset($args['sprint']) && trim((string) $args['sprint']) !== '') {
            return null;
        }

        $active = $project->sprints()->active()->first();

        if ($active === null) {
            return null;
        }

        return [
            'success' => false,
            'error_code' => 'sprint_choice_required',
            'awaiting_input' => true,
            'error' => "\"{$active->name}\" is running on {$project->name}. Should this task go into it?",
            'sprint' => [
                'id' => $active->id,
                'name' => UntrustedText::inline($active->name),
                'ends_on' => $active->ends_on?->toDateString(),
            ],
            'next_step' => 'Ask the user whether to add the task to this running sprint or leave it in the backlog. '
                .'Then call create_task again with the same details plus sprint="current" for the sprint, '
                .'or sprint="none" to keep it out.',
        ];
    }

    /**
     * `is_default` marks the three columns every project ships with, not where
     * new work lands, so handing it to the assistant would mislead it. What it
     * needs is which column a task goes to when nobody picks — the same one
     * CreateTaskAction falls back to.
     *
     * @param  Collection<int, BoardColumn>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function summariseColumns($columns): array
    {
        $starting = $this->startingColumn($columns);

        return $columns
            ->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'name' => UntrustedText::inline($column->name),
                'is_starting_list' => $column->id === $starting?->id,
                'is_done_list' => $column->is_done,
            ])
            ->values()
            ->all();
    }

    /**
     * People call these lists; some still say columns, and the model may echo
     * back either name. Both spellings of the argument are accepted.
     *
     * @param  array<string, mixed>  $args
     */
    private function namedList(array $args): string
    {
        return trim((string) ($args['board_list'] ?? $args['board_column'] ?? ''));
    }

    /**
     * @param  Collection<int, BoardColumn>  $columns
     */
    private function startingColumn($columns): ?BoardColumn
    {
        return $columns->firstWhere('is_default', true) ?? $columns->first();
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
