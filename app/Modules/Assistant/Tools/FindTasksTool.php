<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\AssigneeResolver;
use App\Modules\Assistant\Support\FuzzyMatcher;
use App\Modules\Assistant\Support\ProjectResolver;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the task a person is talking about from however they happen to describe
 * it. "the UI UX task", "that checkout bug", "Rana's overdue one" — all of it
 * lands here, and the answer is always a ranked shortlist rather than a guess.
 */
final class FindTasksTool implements AssistantTool
{
    /** Rows pulled into memory for scoring. Comfortably above any real project's backlog. */
    private const SCAN_LIMIT = 400;

    private const MAX_RESULTS = 10;

    private const MAX_SUGGESTIONS = 5;

    /** A top match this strong, this far clear of the next one, needs no confirmation. */
    private const DECISIVE_GAP = 15;

    public function __construct(
        private readonly ProjectResolver $projectResolver,
        private readonly AssigneeResolver $assigneeResolver,
    ) {}

    public function name(): string
    {
        return 'find_tasks';
    }

    public function description(): string
    {
        return 'Finds tasks by however the user described them, matching loosely — "the UI UX task" finds '
            .'"UI/UX modification", and near-misses and typos still match. ALWAYS call this before assigning, '
            .'updating, moving or completing a task, to turn what the user said into a real task_id. '
            .'Returns a ranked shortlist with a match_confidence for each. When needs_disambiguation is true, '
            .'show the user the candidates and ask which one they mean — never pick for them. '
            .'Filter with project_id or project_name, assignee, and status. Never invent a task_id.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What the user called the task, in their words. Leave out to list tasks instead of searching.',
                    'maxLength' => 150,
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Optional project ID to search inside one project only.',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'Optional project name when the user named a project but you do not have its ID. Matched loosely.',
                    'maxLength' => 100,
                ],
                'assignee' => [
                    'type' => 'string',
                    'description' => 'Optional filter by who holds the task: a name, an email address, "me", or "unassigned".',
                    'maxLength' => 100,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['open', 'done', 'overdue', 'all'],
                    'description' => 'Which tasks to consider. Defaults to open ones, which is what people usually mean.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many matches to return, 1-10. Defaults to 10.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->workspace !== null;
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

        /** @var Collection<int, Project> $projects */
        $projects = $workspace->accessibleProjectsFor($user)->get(['id', 'name', 'workspace_id']);

        if ($projects->isEmpty()) {
            return [
                'success' => true,
                'tasks' => [],
                'total_matches' => 0,
                'needs_disambiguation' => false,
                'message' => 'You are not on any project in this workspace yet, so there are no tasks to search.',
            ];
        }

        /* A named project narrows the search; without one we search everything they can see. */
        if (isset($args['project_id']) || ! empty($args['project_name'])) {
            $resolution = $this->projectResolver->resolve($context, $args, 'this task');

            if (! $resolution->isResolved()) {
                return $resolution->toolPayload();
            }

            $projects = $projects->where('id', $resolution->project->id)->values();
        }

        $assigneeFilter = $this->assigneeFilter($args, $projects, $context);

        if (is_array($assigneeFilter)) {
            return $assigneeFilter;
        }

        $status = (string) ($args['status'] ?? 'open');
        $candidates = $this->candidates($projects, $status, $assigneeFilter);
        $query = isset($args['query']) ? trim((string) $args['query']) : '';
        $limit = max(1, min(self::MAX_RESULTS, (int) ($args['limit'] ?? self::MAX_RESULTS)));

        if ($query === '') {
            return $this->listing($workspace, $projects, $candidates, $status, $limit);
        }

        $ranked = FuzzyMatcher::rank(
            $query,
            $candidates,
            fn (Task $task) => [$task->title, $task->description],
        );

        if ($ranked === []) {
            return $this->nothingFound($workspace, $projects, $candidates, $query, $status);
        }

        $matches = array_slice($ranked, 0, $limit);
        $top = $matches[0];
        $runnerUp = $matches[1]['score'] ?? 0;

        $decisive = count($ranked) === 1
            || ($top['score'] >= FuzzyMatcher::CONFIDENT && ($top['score'] - $runnerUp) >= self::DECISIVE_GAP);

        return [
            'success' => true,
            'query' => UntrustedText::inline($query),
            'status_filter' => $status,
            'searched_projects' => $projects->count(),
            'total_matches' => count($ranked),
            'returned' => count($matches),
            'needs_disambiguation' => ! $decisive,
            'best_match_task_id' => $decisive ? $top['item']->id : null,
            'next_step' => $decisive
                ? 'This is a confident single match — you can use best_match_task_id directly.'
                : 'Show the user these candidates with their project names and ask which one they mean before acting.',
            'tasks' => array_map(
                fn (array $row) => $this->present($row['item'], $workspace, $row['score']),
                $matches,
            ),
        ];
    }

    /**
     * Either a user to filter by, null for no filter, 'unassigned', or a tool
     * error payload when the named person could not be pinned down.
     *
     * @param  array<string, mixed>  $args
     * @param  Collection<int, Project>  $projects
     * @return User|string|array<string, mixed>|null
     */
    private function assigneeFilter(array $args, Collection $projects, ToolContext $context): User|string|array|null
    {
        $assignee = isset($args['assignee']) ? trim((string) $args['assignee']) : '';

        if ($assignee === '') {
            return null;
        }

        if (in_array(mb_strtolower($assignee), ['unassigned', 'nobody', 'no one'], true)) {
            return 'unassigned';
        }

        if (in_array(mb_strtolower($assignee), ['me', 'myself', 'mine'], true)) {
            return $context->user;
        }

        /* Resolving against one project gives better names; across many, first hit wins. */
        foreach ($projects as $project) {
            $resolution = $this->assigneeResolver->resolve($project, $assignee);

            if ($resolution->isResolved()) {
                return $resolution->user;
            }
        }

        return [
            'success' => false,
            'error_code' => 'assignee_not_found',
            'error' => "Nobody matching \"{$assignee}\" holds tasks in these projects.",
            'next_step' => 'Ask the user who they meant, or drop the assignee filter and search by task name instead.',
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Task>
     */
    private function candidates(Collection $projects, string $status, User|string|null $assignee): Collection
    {
        return Task::query()
            ->with(['assignee:id,name,email', 'boardColumn:id,name,is_done', 'sprint:id,name', 'project:id,name'])
            ->whereIn('project_id', $projects->pluck('id'))
            ->when($status === 'open', fn (Builder $query) => $query->open())
            ->when($status === 'done', fn (Builder $query) => $query->completed())
            ->when($status === 'overdue', fn (Builder $query) => $query->overdue())
            ->when($assignee === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($assignee instanceof User, fn (Builder $query) => $query->where('assigned_to', $assignee->id))
            ->latest('id')
            ->limit(self::SCAN_LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Task>  $candidates
     * @return array<string, mixed>
     */
    private function listing(
        Workspace $workspace,
        Collection $projects,
        Collection $candidates,
        string $status,
        int $limit,
    ): array {
        $tasks = $candidates->take($limit);

        return [
            'success' => true,
            'status_filter' => $status,
            'searched_projects' => $projects->count(),
            'total_matches' => $candidates->count(),
            'returned' => $tasks->count(),
            'needs_disambiguation' => $tasks->count() > 1,
            'best_match_task_id' => $tasks->count() === 1 ? $tasks->first()->id : null,
            'tasks' => $tasks->map(fn (Task $task) => $this->present($task, $workspace))->values()->all(),
        ];
    }

    /**
     * Nothing matched — hand back what does exist so the assistant can offer
     * something useful instead of a dead end.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Task>  $candidates
     * @return array<string, mixed>
     */
    private function nothingFound(
        Workspace $workspace,
        Collection $projects,
        Collection $candidates,
        string $query,
        string $status,
    ): array {
        $scope = $projects->count() === 1
            ? '"'.UntrustedText::inline((string) $projects->first()->name).'"'
            : 'any project you can see';

        return [
            'success' => true,
            'query' => UntrustedText::inline($query),
            'status_filter' => $status,
            'total_matches' => 0,
            'returned' => 0,
            'needs_disambiguation' => false,
            'tasks' => [],
            'suggestions' => $candidates
                ->take(self::MAX_SUGGESTIONS)
                ->map(fn (Task $task) => $this->present($task, $workspace))
                ->values()
                ->all(),
            'message' => "No task in {$scope} looks like \"{$query}\"."
                .($status === 'open' ? ' Only open tasks were searched — try status="all" for finished work.' : ''),
            'next_step' => 'Tell the user nothing matched, offer the suggestions if any look close, '
                .'and ask whether they want a new task created instead.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Task $task, Workspace $workspace, ?int $confidence = null): array
    {
        $presented = [
            'task_id' => $task->id,
            'title' => UntrustedText::inline($task->title),
            'project_id' => $task->project_id,
            'project_name' => UntrustedText::inline($task->project?->name),
            'column' => UntrustedText::inline($task->boardColumn?->name),
            'is_done' => $task->isCompleted(),
            'assignee_name' => UntrustedText::inline($task->assignee?->name),
            'assignee_email' => UntrustedText::inline($task->assignee?->email),
            'due_date' => $task->due_date?->toDateString(),
            'is_overdue' => ! $task->isCompleted()
                && $task->due_date !== null
                && $task->due_date->lessThan(now()->startOfDay()),
            'sprint_name' => UntrustedText::inline($task->sprint?->name),
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $task->project_id,
            ])."?task={$task->id}",
        ];

        if ($confidence !== null) {
            $presented['match_confidence'] = $confidence;
        }

        return $presented;
    }
}
