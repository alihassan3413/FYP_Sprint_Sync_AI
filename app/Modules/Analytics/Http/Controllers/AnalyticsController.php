<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Actions\BuildAnalyticsAction;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController
{
    public function index(Request $request, Workspace $workspace, BuildAnalyticsAction $action): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $accessibleProjects = $workspace->accessibleProjectsFor($user)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('analytics/index', [
            'analytics' => $action->handle($accessibleProjects, $filters),
            'filters' => [
                'project_id' => $filters['project_id'] ?? null,
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'projects' => $accessibleProjects->map(fn ($project) => ['id' => $project->id, 'name' => $project->name])->values(),
        ]);
    }
}
