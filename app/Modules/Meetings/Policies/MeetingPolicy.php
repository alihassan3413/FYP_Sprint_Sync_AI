<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Policies;

use App\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Projects\Models\Project;
use App\UserRole;

final class MeetingPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $project->workspace->hasMember($user);
    }

    public function view(User $user, Meeting $meeting): bool
    {
        return $meeting->workspace->hasMember($user);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function update(User $user, Meeting $meeting): bool
    {
        return $meeting->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        return $meeting->workspace->userHasAtLeast($user, UserRole::ADMIN);
    }
}
