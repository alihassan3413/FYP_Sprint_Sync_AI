<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Actions\BuildSprintReport;
use App\Modules\Projects\Data\SprintReportData;
use App\Modules\Projects\Data\SprintStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * The assistant's window into how a sprint is actually going. Read-only, and
 * deliberately verbose: the model should be able to answer "are we going to
 * make it?" without a second round trip.
 */
final class SprintReportTool implements AssistantTool
{
    private const MAX_SPRINTS = 10;

    private const MAX_BURNDOWN_POINTS = 14;

    public function name(): string
    {
        return 'get_sprint_report';
    }

    public function description(): string
    {
        return 'Reports on sprints in the current workspace: status, dates, days left, how much is done versus '
            .'how much time has gone, a health verdict, who is carrying what, overdue blockers, the burndown, '
            .'and the team velocity. Use this whenever the user asks about a sprint, the current sprint, whether '
            .'the team is on track, what is left, sprint progress, velocity, or asks for a standup or status summary. '
            .'Defaults to the active sprint of every project the user can see. Pass project_id from list_projects to '
            .'narrow it, sprint_id for one specific sprint, or status to look at planned or completed sprints. '
            .'Never guess sprint numbers — read them from here. '
            .'Do not call this for a question about one task; use find_tasks for that.';
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
                    'description' => 'Optional project ID from list_projects, to report on one project only.',
                ],
                'sprint_id' => [
                    'type' => 'integer',
                    'description' => 'Optional sprint ID from an earlier get_sprint_report call, for one specific sprint.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [...SprintStatus::values(), 'all'],
                    'description' => 'Which sprints to report on. Defaults to active. Use "completed" for past results '
                        .'and velocity questions, "planned" for what is coming up.',
                ],
                'include_burndown' => [
                    'type' => 'boolean',
                    'description' => 'Include the day-by-day burndown series. Use true when the user asks about the '
                        .'burndown, the trend, or whether the team is speeding up or slowing down.',
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

        $projects = $workspace->accessibleProjectsFor($user)->get(['id', 'name']);

        if (isset($args['project_id'])) {
            $projects = $projects->where('id', (int) $args['project_id'])->values();

            if ($projects->isEmpty()) {
                return [
                    'success' => false,
                    'error_code' => 'project_not_found',
                    'error' => 'That project does not exist or you do not have access to it. Use list_projects to see available projects.',
                ];
            }
        }

        if ($projects->isEmpty()) {
            return [
                'success' => true,
                'sprints' => [],
                'message' => 'You do not have access to any project in this workspace yet.',
            ];
        }

        $status = (string) ($args['status'] ?? SprintStatus::Active->value);
        $sprints = $this->resolveSprints($projects, $status, isset($args['sprint_id']) ? (int) $args['sprint_id'] : null);

        if ($sprints->isEmpty()) {
            return [
                'success' => true,
                'status_filter' => $status,
                'sprints' => [],
                'message' => $this->emptyMessage($status, $projects),
            ];
        }

        $projectNames = $projects->pluck('name', 'id');
        $reporter = app(BuildSprintReport::class);
        $includeBurndown = ($args['include_burndown'] ?? false) === true;

        return [
            'success' => true,
            'status_filter' => $status,
            'workspace' => ['id' => $workspace->id, 'name' => UntrustedText::inline($workspace->name)],
            'returned' => $sprints->count(),
            'sprints' => $sprints
                ->map(fn (Sprint $sprint) => $this->present(
                    $reporter->handle($sprint),
                    $sprint,
                    UntrustedText::inline((string) $projectNames->get($sprint->project_id, 'Unknown project')),
                    $workspace,
                    $includeBurndown,
                ))
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, Sprint>
     */
    private function resolveSprints(Collection $projects, string $status, ?int $sprintId): Collection
    {
        $query = Sprint::query()->whereIn('project_id', $projects->pluck('id'));

        if ($sprintId !== null) {
            return $query->whereKey($sprintId)->get();
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('starts_on')
            ->limit(self::MAX_SPRINTS)
            ->get();
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function emptyMessage(string $status, Collection $projects): string
    {
        $scope = $projects->count() === 1
            ? '"'.UntrustedText::inline((string) $projects->first()->name).'"'
            : 'any project you can see';

        return match ($status) {
            SprintStatus::Active->value => "No sprint is currently running in {$scope}. "
                .'A sprint has to be created and then started before it can be reported on.',
            SprintStatus::Planned->value => "There are no planned sprints in {$scope}.",
            SprintStatus::Completed->value => "No sprint has been completed in {$scope} yet, so there is no velocity history.",
            default => "There are no sprints in {$scope} yet.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(
        SprintReportData $report,
        Sprint $sprint,
        ?string $projectName,
        Workspace $workspace,
        bool $includeBurndown,
    ): array {
        $presented = [
            'sprint_id' => $report->sprint_id,
            'name' => UntrustedText::inline($report->name),
            'goal' => UntrustedText::block($report->goal, 300),
            'project_id' => $sprint->project_id,
            'project_name' => $projectName,
            'status' => $report->status,
            'health' => $report->health,
            'health_label' => $report->health_label,
            'summary' => UntrustedText::inline($report->summary, 400),
            'dates' => [
                'starts_on' => $report->starts_on,
                'ends_on' => $report->ends_on,
                'total_days' => $report->total_days,
                'days_elapsed' => $report->days_elapsed,
                'days_remaining' => $report->days_remaining,
                'time_elapsed_percentage' => $report->time_elapsed_percentage,
            ],
            'work' => [
                'total_tasks' => $report->total_tasks,
                'completed_tasks' => $report->completed_tasks,
                'open_tasks' => $report->open_tasks,
                'overdue_tasks' => $report->overdue_tasks,
                'unassigned_tasks' => $report->unassigned_tasks,
                'completion_percentage' => $report->completion_percentage,
                'expected_percentage' => $report->expected_percentage,
                'pace_delta' => $report->pace_delta,
                'committed_task_count' => $report->committed_task_count,
                'scope_added_since_start' => $report->scope_added,
                'carried_over_task_count' => $report->carried_over_task_count,
                'by_column' => $report->column_breakdown,
            ],
            'team' => array_map(
                fn (array $row) => [
                    'name' => UntrustedText::inline($row['name']),
                    'total' => $row['total'],
                    'completed' => $row['completed'],
                ],
                $report->workload,
            ),
            'blockers' => array_map(
                fn (array $row) => [
                    'task_id' => $row['id'],
                    'title' => UntrustedText::inline($row['title']),
                    'due_date' => $row['due_date'],
                    'assignee' => UntrustedText::inline($row['assignee']),
                ],
                $report->blockers,
            ),
            'pace' => [
                'average_cycle_time_days' => $report->average_cycle_time_days,
                'velocity_average_tasks_per_sprint' => $report->velocity_average,
            ],
            'recommendations' => $report->recommendations,
            'url' => route('workspace.projects.show', [
                'workspace' => $workspace->slug,
                'project' => $sprint->project_id,
            ]).'?tab=sprints',
        ];

        if ($includeBurndown) {
            /* Keep the tail: the recent trend is what a question about the burndown is really after. */
            $presented['burndown'] = array_slice($report->burndown, -self::MAX_BURNDOWN_POINTS);
        }

        return $presented;
    }
}
