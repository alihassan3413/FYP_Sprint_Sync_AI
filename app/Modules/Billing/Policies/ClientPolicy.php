<?php

declare(strict_types=1);

namespace App\Modules\Billing\Policies;

use App\Models\User;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * Every decision goes through Workspace::allowsFinance(), so workspace admin
 * rank never grants access to clients by itself.
 */
final class ClientPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::View);
    }

    public function view(User $user, Client $client): bool
    {
        return $client->workspace->allowsFinance($user, FinancePermission::View);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::Manage);
    }

    public function update(User $user, Client $client): bool
    {
        return $client->workspace->allowsFinance($user, FinancePermission::Manage);
    }

    public function archive(User $user, Client $client): bool
    {
        return $client->workspace->allowsFinance($user, FinancePermission::Manage);
    }
}
