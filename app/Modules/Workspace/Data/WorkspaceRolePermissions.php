<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

/**
 * A workspace role's permissions JSON holds four independent sets: workspace
 * permissions, which apply to admins and members; client permissions, which
 * only apply to members whose base role is client; and finance and time
 * permissions, which are never implied by a base role (see FinancePermission
 * and TimePermission).
 */
final class WorkspaceRolePermissions
{
    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [...WorkspacePermission::values(), ...ClientPermission::values(), ...FinancePermission::values(), ...TimePermission::values()];
    }

    /**
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, bool>
     */
    public static function normalise(?array $permissions): array
    {
        return [
            ...WorkspacePermission::normalise($permissions),
            ...ClientPermission::normalise($permissions),
            ...FinancePermission::normalise($permissions),
            ...TimePermission::normalise($permissions),
        ];
    }
}
