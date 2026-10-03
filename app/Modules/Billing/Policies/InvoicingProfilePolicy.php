<?php

declare(strict_types=1);

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * Settings → Invoicing is Finance: owner-only in v1.
 */
final class InvoicingProfilePolicy
{
    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::Manage);
    }
}
