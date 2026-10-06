<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * Expenses are Finance: owner-only in v1, never granted by admin rank.
 */
final class ExpensePolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::View);
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::Manage);
    }
}
