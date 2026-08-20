<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Analytics\Actions\EvaluateProjectHealth;
use App\Modules\Analytics\Actions\ResolveAnalyticsScope;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController
{
    /** Each assessment costs a handful of queries; the page is a summary, not an audit. */
    private const MAX_HEALTH_PROJECTS = 8;

    public function index(
        Request $request,
        Workspace $workspace,
        BuildAnalyticsAction $action,
        ResolveAnalyticsScope $resolveScope,
        ResolveWorkspaceCapabilities $resolveCapabilities,
        EvaluateProjectHealth $evaluateHealth,
    ): Response {
        $user = $request->user();

        abort_unless($resolveCapabilities->handle($workspace, $user)->viewAnalytics, 403);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'sprint_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $scope = $resolveScope->handle($workspace, $user);
        $accessibleProjects = $scope->accessibleProjects;

        /*
         * Health is per project, so the filter narrows it the same way it
         * narrows the totals. Deferred because evaluating every project costs a
         * query each and the page is useful before it arrives.
         */
        $healthProjects = isset($filters['project_id'])
            ? $accessibleProjects->where('id', (int) $filters['project_id'])
            : $accessibleProjects;

        return Inertia::render('analytics/index', [
            'analytics' => $action->handle($scope, $filters),
            'health' => Inertia::defer(
                fn () => $healthProjects
                    ->take(self::MAX_HEALTH_PROJECTS)
                    ->map(fn ($project) => $evaluateHealth->handle($project))
                    ->values(),
            ),
            'filters' => [
                'project_id' => $filters['project_id'] ?? null,
                'sprint_id' => $filters['sprint_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'projects' => $accessibleProjects->map(fn ($project) => ['id' => $project->id, 'name' => $project->name])->values(),
            'sprints' => Sprint::query()
                ->forProjects($accessibleProjects->pluck('id')->all())
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (Sprint $sprint) => [
                    'id' => $sprint->id,
                    'name' => $sprint->name,
                    'project_id' => $sprint->project_id,
                    'is_current' => $sprint->isCurrent(),
                ])
                ->values(),
        ]);
    }
}
