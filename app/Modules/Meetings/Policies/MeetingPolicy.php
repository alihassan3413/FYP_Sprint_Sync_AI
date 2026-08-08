<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Policies;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\ProjectRole;
use App\UserRole;

final class MeetingPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->hasMember($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if ($meeting->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $meeting->project->hasMember($user);
    }

    public function create(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        if ($meeting->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $meeting->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        if ($meeting->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $meeting->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
