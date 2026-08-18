<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Models\User;
use App\Modules\Analytics\Data\AnalyticsScope;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;

final class ResolveAnalyticsScope
{
    public function handle(Workspace $workspace, User $user): AnalyticsScope
    {
        $accessibleProjects = $workspace->accessibleProjectsFor($user)->orderBy('name')->get();

        if ($workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return AnalyticsScope::teamWide($accessibleProjects);
        }

        $managedProjectIds = $workspace->managedProjectsFor($user)
            ->pluck('id')
            ->intersect($accessibleProjects->pluck('id'))
            ->values();

        return new AnalyticsScope($accessibleProjects, $managedProjectIds, $user->id);
    }
}
