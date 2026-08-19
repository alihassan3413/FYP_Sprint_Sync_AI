<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Actions\BuildAssistantUsageReport;
use App\Modules\Admin\Actions\BuildPlatformMetrics;
use App\Modules\Admin\Actions\BuildSystemDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only platform overview. Access is gated by EnsureUserIsSuperAdmin on
 * the route; nothing here mutates state.
 */
final class AdminDashboardController
{
    public function __invoke(
        Request $request,
        BuildPlatformMetrics $metrics,
        BuildAssistantUsageReport $assistantUsage,
        BuildSystemDirectory $directory,
    ): Response {
        $filters = $request->validate([
            'user_search' => ['nullable', 'string', 'max:120'],
            'workspace_search' => ['nullable', 'string', 'max:120'],
        ]);

        $userSearch = $filters['user_search'] ?? null;
        $workspaceSearch = $filters['workspace_search'] ?? null;

        return Inertia::render('admin/index', [
            'metrics' => $metrics->handle(),
            'assistantUsage' => $assistantUsage->handle(),
            'users' => $directory->users($userSearch),
            'workspaces' => $directory->workspaces($workspaceSearch),
            'filters' => [
                'user_search' => $userSearch ?? '',
                'workspace_search' => $workspaceSearch ?? '',
            ],
        ]);
    }
}
