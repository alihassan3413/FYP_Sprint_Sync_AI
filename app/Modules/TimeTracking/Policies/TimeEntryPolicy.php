<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Policies;

use App\Models\User;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * - Everyone in the workspace except client guests tracks and sees their own
 *   time (through their linked Team profile).
 * - Other people's time, historical hours, corrections and voids are
 *   owner-only, behind the same boundary as Team profiles: admin rank grants
 *   nothing here.
 */
final class TimeEntryPolicy
{
    public function track(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user) && ! $workspace->isClient($user);
    }

    public function viewTeam(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::PeopleView);
    }

    public function manageTeam(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::PeopleManage);
    }

    public function void(User $user, TimeEntry $entry): bool
    {
        return $entry->workspace->allowsFinance($user, FinancePermission::PeopleManage);
    }
}
