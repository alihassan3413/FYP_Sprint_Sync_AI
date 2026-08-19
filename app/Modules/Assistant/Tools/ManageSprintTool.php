<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Contracts\ProvidesConfirmationDetails;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Actions\CompleteSprintAction;
use App\Modules\Projects\Actions\CreateSprintAction;
use App\Modules\Projects\Actions\StartSprintAction;
use App\Modules\Projects\Data\SprintCarryOver;
use App\Modules\Projects\Data\StoreSprintData;
use App\Modules\Projects\Exceptions\SprintException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The write half of sprint management: plan one, start it, close it. Every path
 * goes through the same domain actions the UI uses, so the rules (one active
 * sprint per project, frozen history, carry-over) hold here too.
 */
final class ManageSprintTool implements AssistantTool, ProvidesConfirmationDetails
{
    private const DEFAULT_LENGTH_DAYS = 14;

    public function __construct(
        private readonly CreateSprintAction $createSprint,
        private readonly StartSprintAction $startSprint,
        private readonly CompleteSprintAction $completeSprint,
    ) {}

    public function name(): string
    {
        return 'manage_sprint';
    }

    public function description(): string
    {
        return 'Creates, starts or completes a sprint. Use action="create" to plan a new sprint (call list_projects '
            .'first for the project_id; the sprint is two weeks long unless the user says otherwise, and starts out '
            .'planned, not running). Use action="start" to commit the sprint and begin it — a project can only have '
            .'one running sprint. Use action="complete" to close a running sprint, which freezes its numbers and '
            .'moves unfinished work either to the backlog or into the next planned sprint (carry_over). '
            .'Get sprint_id from get_sprint_report, never invent one.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'start', 'complete'],
                    'description' => 'What to do with the sprint.',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Project ID from list_projects. Required for action="create".',
                ],
                'sprint_id' => [
                    'type' => 'integer',
                    'description' => 'Sprint ID from get_sprint_report. Required for action="start" and action="complete".',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Sprint name for action="create", e.g. "Sprint 4". Ask the user if they did not say.',
                    'maxLength' => 80,
                ],
                'goal' => [
                    'type' => 'string',
                    'description' => 'Optional one-line goal describing what the sprint is meant to achieve.',
                    'maxLength' => 2000,
                ],
                'starts_on' => [
                    'type' => 'string',
                    'description' => 'Start date for action="create" as YYYY-MM-DD. Defaults to today. '
                        .'Resolve relative dates like "next Monday" against the current date given above.',
                ],
                'ends_on' => [
                    'type' => 'string',
                    'description' => 'End date for action="create" as YYYY-MM-DD. Defaults to two weeks after the start.',
                ],
                'carry_over' => [
                    'type' => 'string',
                    'enum' => SprintCarryOver::values(),
                    'description' => 'For action="complete": where unfinished tasks go. Defaults to the backlog.',
                ],
            ],
            'required' => ['action'],
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
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array
    {
        $workspace = $context->workspace;
        $action = (string) ($args['action'] ?? 'create');

        if ($workspace === null) {
            return ['action' => $action];
        }

        if ($action === 'create') {
            $project = $this->resolveProject($workspace, $args, $context);
            [$startsOn, $endsOn] = $this->resolveDates($args);

            return [
                'action' => 'Plan a new sprint',
                'project' => $project === null ? 'Unknown project' : (UntrustedText::inline($project->name) ?? 'Unknown project'),
                'sprint' => UntrustedText::inline((string) ($args['name'] ?? 'Untitled sprint')) ?? 'Untitled sprint',
                'dates' => "{$startsOn} to {$endsOn}",
                'note' => 'It will be created as planned. Nothing starts until you start it.',
            ];
        }

        $sprint = $this->resolveSprint($workspace, $args, $context);

        if ($sprint === null) {
            return ['action' => $action, 'sprint' => 'Unknown sprint'];
        }

        if ($action === 'start') {
            return [
                'action' => 'Start this sprint',
                'sprint' => UntrustedText::inline($sprint->name) ?? 'Sprint',
                'dates' => "{$sprint->starts_on->toDateString()} to {$sprint->ends_on->toDateString()}",
                'commits' => $sprint->tasks()->count().' task(s) will be committed as its scope.',
            ];
        }

        $open = $sprint->tasks()->open()->count();
        $carryOver = SprintCarryOver::tryFrom((string) ($args['carry_over'] ?? '')) ?? SprintCarryOver::Backlog;

        return [
            'action' => 'Complete this sprint',
            'sprint' => UntrustedText::inline($sprint->name) ?? 'Sprint',
            'done' => $sprint->tasks()->completed()->count().' task(s) finished.',
            'unfinished' => $open.' task(s) still open — '.$carryOver->label().'.',
            'note' => 'Completing a sprint freezes its numbers and cannot be undone.',
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

        try {
            return match ((string) ($args['action'] ?? '')) {
                'create' => $this->create($args, $context, $workspace),
                'start' => $this->start($args, $context, $workspace),
                'complete' => $this->complete($args, $context, $workspace),
                default => [
                    'success' => false,
                    'error_code' => 'unknown_action',
                    'error' => 'action must be one of: create, start, complete.',
                ],
            };
        } catch (SprintException $exception) {
            return [
                'success' => false,
                'error_code' => $exception->getErrorCode(),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function create(array $args, ToolContext $context, Workspace $workspace): array
    {
        $project = $this->resolveProject($workspace, $args, $context);

        if ($project === null) {
            return [
                'success' => false,
                'error_code' => 'project_not_found',
                'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
            ];
        }

        if (! $context->user->can('create', [Sprint::class, $project])) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to plan sprints in {$project->name}.",
            ];
        }

        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return [
                'success' => false,
                'error_code' => 'missing_name',
                'error' => 'A sprint needs a name. Ask the user what to call it.',
            ];
        }

        [$startsOn, $endsOn] = $this->resolveDates($args);

        $clash = Sprint::query()
            ->where('project_id', $project->id)
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->first();

        if ($clash !== null) {
            return [
                'success' => false,
                'error_code' => 'overlapping_sprint',
                'error' => "\"{$clash->name}\" already covers {$clash->starts_on->toDateString()} to "
                    ."{$clash->ends_on->toDateString()} in this project. Sprints cannot overlap.",
            ];
        }

        $sprint = $this->createSprint->handle($project, $context->user, StoreSprintData::from([
            'name' => $name,
            'goal' => isset($args['goal']) ? trim((string) $args['goal']) : null,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]));

        return [
            'success' => true,
            'sprint' => $this->summarise($sprint, $workspace),
            'message' => "Planned \"{$sprint->name}\" in {$project->name} from {$startsOn} to {$endsOn}. "
                .'It is not running yet — start it when the team is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function start(array $args, ToolContext $context, Workspace $workspace): array
    {
        $sprint = $this->resolveSprint($workspace, $args, $context);

        if ($sprint === null) {
            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => 'That sprint does not exist or you do not have access to it. Use get_sprint_report to find it.',
            ];
        }

        if (! $context->user->can('start', $sprint)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to run sprints in {$sprint->project->name}.",
            ];
        }

        $started = $this->startSprint->handle($sprint, $context->user);

        return [
            'success' => true,
            'sprint' => $this->summarise($started, $workspace),
            'message' => "\"{$started->name}\" is now running with {$started->committed_task_count} committed "
                .($started->committed_task_count === 1 ? 'task' : 'tasks').'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function complete(array $args, ToolContext $context, Workspace $workspace): array
    {
        $sprint = $this->resolveSprint($workspace, $args, $context);

        if ($sprint === null) {
            return [
                'success' => false,
                'error_code' => 'sprint_not_found',
                'error' => 'That sprint does not exist or you do not have access to it. Use get_sprint_report to find it.',
            ];
        }

        if (! $context->user->can('complete', $sprint)) {
            return [
                'success' => false,
                'error_code' => 'unauthorized',
                'error' => "You do not have permission to run sprints in {$sprint->project->name}.",
            ];
        }

        $carryOver = SprintCarryOver::tryFrom((string) ($args['carry_over'] ?? '')) ?? SprintCarryOver::Backlog;
        $completed = $this->completeSprint->handle($sprint, $context->user, $carryOver);

        $destination = $carryOver === SprintCarryOver::NextSprint ? 'the next planned sprint' : 'the backlog';

        return [
            'success' => true,
            'sprint' => $this->summarise($completed, $workspace),
            'message' => "\"{$completed->name}\" is complete: {$completed->completed_task_count} done, "
                ."{$completed->carried_over_task_count} moved to {$destination}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveProject(Workspace $workspace, array $args, ToolContext $context): ?Project
    {
        if (! isset($args['project_id'])) {
            return null;
        }

        return $workspace->accessibleProjectsFor($context->user)
            ->whereKey((int) $args['project_id'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function resolveSprint(Workspace $workspace, array $args, ToolContext $context): ?Sprint
    {
        if (! isset($args['sprint_id'])) {
            return null;
        }

        $projectIds = $workspace->accessibleProjectsFor($context->user)->pluck('id');

        return Sprint::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->whereKey((int) $args['sprint_id'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: string}
     */
    private function resolveDates(array $args): array
    {
        $startsOn = $this->parseDate($args['starts_on'] ?? null) ?? now()->startOfDay();
        $endsOn = $this->parseDate($args['ends_on'] ?? null)
            ?? $startsOn->copy()->addDays(self::DEFAULT_LENGTH_DAYS - 1);

        if ($endsOn->lessThan($startsOn)) {
            $endsOn = $startsOn->copy()->addDays(self::DEFAULT_LENGTH_DAYS - 1);
        }

        return [$startsOn->toDateString(), $endsOn->toDateString()];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Sprint $sprint, Workspace $workspace): array
    {
        return [
            'id' => $sprint->id,
            'name' => UntrustedText::inline($sprint->name),
            'status' => $sprint->status->value,
            'status_label' => $sprint->status->label(),
            'project_id' => $sprint->project_id,
            'starts_on' => $sprint->starts_on->toDateString(),
            'ends_on' => $sprint->ends_on->toDateString(),
            'task_count' => $sprint->tasks()->count(),
            'committed_task_count' => $sprint->committed_task_count,
            'completed_task_count' => $sprint->completed_task_count,
            'carried_over_task_count' => $sprint->carried_over_task_count,
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $sprint->project_id,
            ]).'?tab=sprints',
        ];
    }
}
