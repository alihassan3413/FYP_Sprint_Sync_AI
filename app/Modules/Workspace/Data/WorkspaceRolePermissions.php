<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

/**
 * A workspace role's permissions JSON holds two independent sets: workspace
 * permissions, which apply to admins and members, and client permissions, which
 * only apply to members whose base role is client.
 */
final class WorkspaceRolePermissions
{
    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [...WorkspacePermission::values(), ...ClientPermission::values()];
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
        ];
    }
}
