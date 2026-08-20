<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Analytics\Actions\EvaluateProjectHealth;
use App\Modules\Analytics\Data\ProjectHealthData;
use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\ProjectResolver;
use App\Modules\Assistant\Support\ToolContext;
use App\Modules\Assistant\Support\UntrustedText;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;

/**
 * Reads a project the way a lead would: not just how much is done, but whether
 * the work is spread sensibly, whether anything has stalled, and whether one
 * person is quietly holding the whole thing up.
 */
final class EvaluateProjectTool implements AssistantTool
{
    private const MAX_PROJECTS = 8;

    public function __construct(
        private readonly EvaluateProjectHealth $evaluate,
        private readonly ProjectResolver $projectResolver,
        private readonly ResolveWorkspaceCapabilities $resolveCapabilities,
    ) {}

    public function name(): string
    {
        return 'evaluate_project';
    }

    public function description(): string
    {
        return 'Assesses how a project is really going and how the work is spread across the team. '
            .'Call this when the user asks how a project is performing, whether it is healthy or in trouble, '
            .'who is overloaded, whether the workload is balanced, who is carrying the team, or who has capacity. '
            .'Pass project_name for one project, or leave it out to assess every project they can see. '
            .'It returns a verdict, the numbers behind it, per-person workload, and specific findings — read '
            .'those findings out rather than forming your own diagnosis, and never invent a number.';
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
                    'description' => 'Project ID, when you already have one.',
                ],
                'project_name' => [
                    'type' => 'string',
                    'description' => 'The project as the user said it. Matched loosely. Leave out to assess them all.',
                    'maxLength' => 100,
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

        /*
         * Team workload is staff information. A client sees their own board, not
         * who on the delivery team is overloaded.
         */
        return $this->resolveCapabilities->handle($workspace, $context->user)->viewAnalytics;
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

        $named = isset($args['project_id']) || ! empty($args['project_name']);

        if ($named) {
            $resolution = $this->projectResolver->resolve($context, $args, 'this assessment');

            if (! $resolution->isResolved()) {
                return $resolution->toolPayload();
            }

            return [
                'success' => true,
                'scope' => 'project',
                'assessment' => $this->present($this->evaluate->handle($resolution->project)),
                'next_step' => 'Lead with the verdict and the number behind it, then the findings in order. '
                    .'Do not list every workload row unless the user asked who is doing what.',
            ];
        }

        $projects = $workspace->accessibleProjectsFor($context->user)->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return [
                'success' => true,
                'scope' => 'workspace',
                'assessments' => [],
                'message' => 'There are no projects here to assess yet.',
            ];
        }

        $assessments = $projects
            ->take(self::MAX_PROJECTS)
            ->map(fn (Project $project) => $this->present($this->evaluate->handle($project)))
            ->values()
            ->all();

        return [
            'success' => true,
            'scope' => 'workspace',
            'assessed' => count($assessments),
            'total_projects' => $projects->count(),
            'truncated' => $projects->count() > count($assessments),
            'assessments' => $assessments,
            'next_step' => 'Name the projects that need attention and why, and say the rest look fine. '
                .'Do not walk through every project one by one unless the user asks.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProjectHealthData $health): array
    {
        return [
            'project_id' => $health->project_id,
            'project_name' => UntrustedText::inline($health->project_name),
            'verdict' => $health->verdict,
            'verdict_label' => $health->verdict_label,
            'totals' => [
                'total_tasks' => $health->total_tasks,
                'completed_tasks' => $health->completed_tasks,
                'open_tasks' => $health->open_tasks,
                'completion_percentage' => $health->completion_percentage,
                'overdue_tasks' => $health->overdue_tasks,
                'unassigned_open_tasks' => $health->unassigned_open_tasks,
                'stale_open_tasks' => $health->stale_open_tasks,
            ],
            'active_sprint' => $health->active_sprint_name === null ? null : [
                'name' => UntrustedText::inline($health->active_sprint_name),
                'health' => $health->active_sprint_health,
            ],
            'people_with_open_work' => $health->people_with_open_work,
            'busiest_share_percentage' => $health->busiest_share_percentage,
            'findings' => array_map(fn ($signal) => [
                'code' => $signal->code,
                'severity' => $signal->severity,
                'headline' => UntrustedText::inline($signal->headline),
                'detail' => UntrustedText::inline($signal->detail),
                'suggestion' => UntrustedText::inline($signal->suggestion),
            ], $health->signals),
            'workload' => array_map(fn ($entry) => [
                'name' => UntrustedText::inline($entry->name),
                'open_tasks' => $entry->open_tasks,
                'overdue_tasks' => $entry->overdue_tasks,
                'completed_tasks' => $entry->completed_tasks,
                'share_percentage' => $entry->share_percentage,
            ], $health->workload),
        ];
    }
}
