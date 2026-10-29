<?php

declare(strict_types=1);

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Modules\People\Models\TeamImport;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;

final class TeamImportPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->roleFor($user)?->atLeast(UserRole::ADMIN) ?? false;
    }

    public function view(User $user, TeamImport $import): bool
    {
        return $this->create($user, $import->workspace);
    }

    public function update(User $user, TeamImport $import): bool
    {
        return $this->create($user, $import->workspace);
    }
}
