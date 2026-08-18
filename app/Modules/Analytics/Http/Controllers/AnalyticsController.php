<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Analytics\Actions\ResolveAnalyticsScope;
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
    ): Response {
        $user = $request->user();

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $scope = $resolveScope->handle($workspace, $user);
        $accessibleProjects = $scope->accessibleProjects;

        return Inertia::render('analytics/index', [
            'analytics' => $action->handle($scope, $filters),
            'filters' => [
                'project_id' => $filters['project_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'projects' => $accessibleProjects->map(fn ($project) => ['id' => $project->id, 'name' => $project->name])->values(),
        ]);
    }
}
