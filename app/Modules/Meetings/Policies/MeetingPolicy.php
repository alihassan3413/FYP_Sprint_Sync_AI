<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Policies;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\ClientPermission;
use App\ProjectRole;
use App\UserRole;

final class MeetingPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        if ($project->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        if ($project->workspace->isClient($user)) {
            return $project->hasMember($user)
                && $project->workspace->allowsClient($user, ClientPermission::MeetingsView);
        }

        return $project->hasMember($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $this->viewAny($user, $meeting->project);
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

    public function manageTranscript(User $user, Meeting $meeting): bool
    {
        return $this->update($user, $meeting);
    }

    public function viewTranscript(User $user, Meeting $meeting): bool
    {
        return $this->view($user, $meeting);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        if ($meeting->workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $meeting->project->userHasAtLeast($user, ProjectRole::MANAGER);
    }
}
