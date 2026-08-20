<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Models\User;
use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Analytics\Actions\ResolveAnalyticsScope;
use App\Modules\Analytics\Data\AnalyticsData;
use App\Modules\Analytics\Data\AnalyticsScope;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

final class GetAnalyticsTool implements AssistantTool
{
    private const SCOPE_AUTO = 'auto';

    private const SCOPE_PERSONAL = 'personal';

    private const TIME_RANGE_NOTE = 'These are current-state totals for everything the user can see. '
        .'The analytics module does not filter tasks by date, so there is no "this week" or "last month" view.';

    public function __construct(
        private readonly ResolveAnalyticsScope $resolveScope,
        private readonly BuildAnalyticsAction $buildAnalytics,
        private readonly ResolveWorkspaceCapabilities $resolveCapabilities,
    ) {}

    public function name(): string
    {
        return 'get_analytics';
    }

    public function description(): string
    {
        return 'Reports current progress across the workspace: how many tasks exist, how many are done, how many '
            .'are open or overdue, the completion percentage, the split across board columns, a per-project '
            .'breakdown, who is carrying what, the running sprint totals, and meeting counts. Use this whenever the '
            .'user asks how things are going, how the team or a project is doing, what is overdue, which project is '
            .'behind, the completion rate, or how they personally are doing. Pass project_id from list_projects to '
            .'report on one project. Pass scope="personal" for "how am I doing" so the numbers cover only tasks '
            .'assigned to the user. Read-only. The figures are current state, not a date range: there is no weekly '
            .'or monthly filter, so never claim a number is "for this week". Use get_sprint_report instead when the '
            .'question is about one sprint\'s pace, burndown, health or velocity.';
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
                    'description' => 'Optional project ID from list_projects, to report on that project only.',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => [self::SCOPE_AUTO, self::SCOPE_PERSONAL],
                    'description' => 'Defaults to auto, which reports the widest data the user is allowed to see. '
                        .'Use personal for questions about the user\'s own workload or performance, which counts '
                        .'only tasks assigned to them.',
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
        $workspace = $context->workspace;

        if ($workspace === null) {
            return false;
        }

        if ($this->resolveCapabilities->handle($workspace, $context->user)->viewAnalytics) {
            return true;
        }

        return $this->hasNothingToReportOn($workspace, $context->user);
    }

    private function hasNothingToReportOn(Workspace $workspace, User $user): bool
    {
        return $workspace->hasMember($user)
            && ! $workspace->isClient($user)
            && ! $workspace->accessibleProjectsFor($user)->exists();
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

        if (! $this->authorize($context)) {
            return [
                'success' => false,
                'error_code' => 'not_permitted',
                'error' => 'You do not have access to analytics in this workspace.',
            ];
        }

        $scope = $this->resolveScope->handle($workspace, $context->user);

        $filters = [];
        $filteredProject = null;

        if (isset($args['project_id'])) {
            $filteredProject = $scope->accessibleProjects->firstWhere('id', (int) $args['project_id']);

            if ($filteredProject === null) {
                return [
                    'success' => false,
                    'error_code' => 'project_not_found',
                    'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
                ];
            }

            $filters['project_id'] = $filteredProject->id;
        }

        if (($args['scope'] ?? self::SCOPE_AUTO) === self::SCOPE_PERSONAL) {
            $scope = $this->narrowToPersonal($scope, $context->user->id);
        }

        if ($scope->accessibleProjects->isEmpty()) {
            return [
                'success' => true,
                'scope' => $scope->label(),
                'workspace' => $this->workspaceRef($workspace),
                'accessible_projects' => 0,
                'message' => 'There are no projects you can see in this workspace yet, so there is nothing to report on.',
                'note' => self::TIME_RANGE_NOTE,
            ];
        }

        return $this->present(
            $this->buildAnalytics->handle($scope, $filters),
            $scope,
            $workspace,
            $filteredProject,
        );
    }

    private function narrowToPersonal(AnalyticsScope $scope, int $userId): AnalyticsScope
    {
        return new AnalyticsScope($scope->accessibleProjects, new Collection, $userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        AnalyticsData $analytics,
        AnalyticsScope $scope,
        Workspace $workspace,
        ?Project $filteredProject,
    ): array {
        $presented = [
            'success' => true,
            'scope' => $analytics->scope,
            'scope_explanation' => $this->explainScope($scope),
            'workspace' => $this->workspaceRef($workspace),
            'filtered_to_project' => $filteredProject === null ? null : [
                'id' => $filteredProject->id,
                'name' => UntrustedText::inline($filteredProject->name),
            ],
            'accessible_projects' => $analytics->total_projects,
            'tasks' => [
                'total' => $analytics->total_tasks,
                'completed' => $analytics->completed_tasks,
                'open' => $analytics->open_tasks,
                'overdue' => $analytics->overdue_tasks,
                'completion_percentage' => $analytics->task_completion_percentage,
                'by_column' => array_map(
                    fn ($column) => [
                        'name' => UntrustedText::inline($column->name),
                        'is_done' => $column->is_done,
                        'count' => $column->count,
                    ],
                    $analytics->tasks_by_column,
                ),
                'by_assignee' => array_map(
                    fn ($assignee) => [
                        'assignee_id' => $assignee->assignee_id,
                        'name' => UntrustedText::inline($assignee->name),
                        'count' => $assignee->count,
                    ],
                    $analytics->tasks_by_assignee,
                ),
            ],
            'projects' => array_map(
                fn ($project) => [
                    'id' => $project->id,
                    'name' => UntrustedText::inline($project->name),
                    'total_tasks' => $project->total_tasks,
                    'completed_tasks' => $project->completed_tasks,
                    'completion_percentage' => $project->completion_percentage,
                ],
                $analytics->projects,
            ),
            'meetings' => [
                'total' => $analytics->total_meetings,
                'upcoming' => $analytics->upcoming_meetings,
                'past' => $analytics->past_meetings,
            ],
            'current_sprint' => $this->presentSprint($analytics),
            'note' => self::TIME_RANGE_NOTE,
            'url' => route('workspace.analytics.index', ['workspace' => $workspace->slug]),
        ];

        if ($analytics->total_tasks === 0) {
            $presented['message'] = $filteredProject !== null
                ? 'That project has no tasks matching what you can see, so every total is zero.'
                : 'There are no tasks matching what you can see yet, so every total is zero.';
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSprint(AnalyticsData $analytics): array
    {
        $sprint = $analytics->sprint_progress;

        if (! $sprint->has_sprint) {
            return ['has_sprint' => false];
        }

        return [
            'has_sprint' => true,
            'sprints' => array_map(
                fn ($ref) => [
                    'id' => $ref->id,
                    'name' => UntrustedText::inline($ref->name),
                    'project_id' => $ref->project_id,
                    'project_name' => UntrustedText::inline($ref->project_name),
                    'starts_on' => $ref->starts_on,
                    'ends_on' => $ref->ends_on,
                ],
                $sprint->sprints,
            ),
            'total_tasks' => $sprint->total_tasks,
            'completed_tasks' => $sprint->completed_tasks,
            'open_tasks' => $sprint->open_tasks,
            'completion_percentage' => $sprint->completion_percentage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceRef(Workspace $workspace): array
    {
        return ['id' => $workspace->id, 'name' => UntrustedText::inline($workspace->name)];
    }

    private function explainScope(AnalyticsScope $scope): string
    {
        if ($scope->label() === AnalyticsScope::PERSONAL) {
            return 'Personal: counts only tasks assigned to the current user.';
        }

        if ($scope->personalUserId === null) {
            return 'Team-wide: counts every task in the projects the user can see.';
        }

        return 'Mixed: team-wide for the projects the user manages, and their own tasks only in projects where '
            .'they are just a member.';
    }
}
