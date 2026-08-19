<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Analytics\Actions\ResolveAnalyticsScope;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Workspace\Actions\ResolveWorkspaceCapabilities;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController
{
    public function index(
        Request $request,
        Workspace $workspace,
        BuildAnalyticsAction $action,
        ResolveAnalyticsScope $resolveScope,
        ResolveWorkspaceCapabilities $resolveCapabilities,
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

        return Inertia::render('analytics/index', [
            'analytics' => $action->handle($scope, $filters),
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
