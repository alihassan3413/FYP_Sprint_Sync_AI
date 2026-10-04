<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Support;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Who and what time can be recorded against, for a signed-in user. Records
 * are looked up by public id inside the workspace only, so another
 * workspace's project, task or person simply is not found.
 */
final class TimeAccess
{
    /** The user's own Team profile in this workspace (time is always recorded for a person). */
    public function personFor(User $user, Workspace $workspace): ?Person
    {
        return Person::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->first();
    }

    public function person(Workspace $workspace, ?string $publicId): ?Person
    {
        return $publicId === null ? null : Person::query()->where('workspace_id', $workspace->id)->where('public_id', $publicId)->first();
    }

    /**
     * Projects this user may record time on: all of them for the owner, the
     * projects they can open for everyone else.
     *
     * @return Collection<int, Project>
     */
    public function projects(User $user, Workspace $workspace): Collection
    {
        $manager = $user->can('manageTeam', [TimeEntry::class, $workspace]);

        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Project $project) => $manager || $user->can('view', $project))
            ->values();
    }

    public function project(User $user, Workspace $workspace, ?string $publicId): ?Project
    {
        return $publicId === null ? null : $this->projects($user, $workspace)->firstWhere('public_id', $publicId);
    }

    public function task(?Project $project, ?string $publicId): ?Task
    {
        if ($project === null || $publicId === null || $publicId === '') {
            return null;
        }

        return Task::query()->where('project_id', $project->id)->where('workspace_id', $project->workspace_id)->where('public_id', $publicId)->first();
    }
}
