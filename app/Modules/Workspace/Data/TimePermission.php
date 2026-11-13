<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

/**
 * Access to other people's time, delegated through a custom role. The owner
 * always holds all three; admin rank grants nothing on its own, so an admin
 * or a "Manager" role only gets them when the owner switches them on.
 *
 * Editing or approving someone's time implies seeing it. Time settings (the
 * time policy, schedules, holidays and leave) stay owner-only.
 */
enum TimePermission: string
{
    case ViewTeam = 'time.view';
    case EditTeam = 'time.edit';
    case ApproveTeam = 'time.approve';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, bool>
     */
    public static function normalise(?array $permissions): array
    {
        $normalised = [];

        foreach (self::cases() as $permission) {
            $normalised[$permission->value] = ($permissions[$permission->value] ?? false) === true;
        }

        return $normalised;
    }
}
