<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Support;

enum WorkspacePermission: string
{
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsDelete = 'projects.delete';
    case MembersInvite = 'members.invite';
    case MembersRemove = 'members.remove';
    case MembersRoles = 'members.roles';
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case IntegrationsDeploy = 'integrations.deploy';

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
