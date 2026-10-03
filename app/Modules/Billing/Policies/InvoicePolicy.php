<?php

declare(strict_types=1);

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * Owner-only in v1, decided by Workspace::allowsFinance(). Viewing, editing
 * drafts and approving are separate permissions so they can be delegated
 * independently later.
 */
final class InvoicePolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::View);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->workspace->allowsFinance($user, FinancePermission::View);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->workspace->allowsFinance($user, FinancePermission::Manage);
    }

    public function approve(User $user, Invoice $invoice): bool
    {
        return $invoice->workspace->allowsFinance($user, FinancePermission::Approve);
    }

    /** Undoing an approval is part of approving. */
    public function cancelSending(User $user, Invoice $invoice): bool
    {
        return $invoice->workspace->allowsFinance($user, FinancePermission::Approve);
    }
}
