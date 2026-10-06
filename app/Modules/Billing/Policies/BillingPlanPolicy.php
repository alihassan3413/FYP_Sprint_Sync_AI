<?php

declare(strict_types=1);

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Data\FinancePermission;

/**
 * Recurring invoices follow the client they belong to: owner-only in v1,
 * decided by Workspace::allowsFinance(), never by admin rank.
 */
final class BillingPlanPolicy
{
    public function create(User $user, Client $client): bool
    {
        return $client->workspace->allowsFinance($user, FinancePermission::Manage);
    }

    public function update(User $user, BillingPlan $plan): bool
    {
        return $plan->workspace->allowsFinance($user, FinancePermission::Manage);
    }

    public function generateInvoice(User $user, BillingPlan $plan): bool
    {
        return $plan->workspace->allowsFinance($user, FinancePermission::Manage);
    }
}
