<?php

declare(strict_types=1);

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * Email delivery settings hold the workspace's mail password: owner-only.
 */
final class WorkspaceMailSettingsPolicy
{
    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::Manage);
    }
}
