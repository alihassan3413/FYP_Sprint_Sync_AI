<?php

declare(strict_types=1);

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * People data sits behind the same owner-only boundary as Finance in v1:
 * every decision goes through Workspace::allowsFinance(), so workspace admin
 * rank grants nothing by itself.
 */
final class PersonPolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::PeopleView);
    }

    public function view(User $user, Person $person): bool
    {
        return $person->workspace->allowsFinance($user, FinancePermission::PeopleView);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::PeopleManage);
    }

    public function update(User $user, Person $person): bool
    {
        return $person->workspace->allowsFinance($user, FinancePermission::PeopleManage);
    }
}
