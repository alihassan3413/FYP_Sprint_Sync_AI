<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Policies;

use App\Models\User;
use App\Modules\TimeTracking\Models\TimeEntry;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Data\TimePermission;
use App\Modules\Workspace\Models\Workspace;

/**
 * - Everyone in the workspace except client guests tracks and sees their own
 *   time (through their linked Team profile).
 * - Other people's time is the owner's, plus whatever a custom role delegates
 *   (see TimePermission): seeing it, editing it (add, correct, void, previous
 *   hours) and approving flagged time. Editing or approving implies seeing.
 * - Time settings stay owner-only, behind the same boundary as Team profiles.
 */
final class TimeEntryPolicy
{
    public function track(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user) && ! $workspace->isClient($user);
    }

    public function viewTeam(User $user, Workspace $workspace): bool
    {
        foreach (TimePermission::cases() as $permission) {
            if ($workspace->allowsTime($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function manageTeam(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsTime($user, TimePermission::EditTeam);
    }

    public function approveTeam(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsTime($user, TimePermission::ApproveTeam);
    }

    public function configure(User $user, Workspace $workspace): bool
    {
        return $workspace->allowsFinance($user, FinancePermission::PeopleManage);
    }

    public function void(User $user, TimeEntry $entry): bool
    {
        return $entry->workspace->allowsTime($user, TimePermission::EditTeam);
    }
}
