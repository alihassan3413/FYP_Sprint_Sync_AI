<?php

declare(strict_types=1);

namespace App\Modules\Audit\Policies;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;

final class AuditLogPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        if ($workspace->userHasAtLeast($user, UserRole::ADMIN)) {
            return true;
        }

        return $workspace->managedProjectsFor($user)->exists();
    }
}
