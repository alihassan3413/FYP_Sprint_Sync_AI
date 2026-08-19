<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Actions;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Projects\Models\Project;
use App\Modules\Workspace\Data\WorkspaceCapabilities;
use App\Modules\Workspace\Models\Workspace;

final class ResolveWorkspaceCapabilities
{
    public function handle(Workspace $workspace, User $user): WorkspaceCapabilities
    {
        $hasAccessibleProjects = $workspace->accessibleProjectsFor($user)->exists();
        $canCreateProjects = $user->can('create', [Project::class, $workspace]);
        $canViewAuditLog = $user->can('viewAny', [AuditLog::class, $workspace]);
        $canManageWorkspace = $user->can('update', $workspace);
        $canDeleteWorkspace = $user->can('delete', $workspace);
        $canManageMembers = $user->can('manageMembers', $workspace);
        $canManageRoles = $user->can('manageRoles', $workspace);
        $canInviteMembers = $user->can('invite', $workspace);

        return new WorkspaceCapabilities(
            viewProjects: $hasAccessibleProjects || $canCreateProjects,
            viewTeam: true,
            viewAnalytics: $hasAccessibleProjects,
            viewArchive: $hasAccessibleProjects,
            viewAudit: $canViewAuditLog,
            viewWorkspaceSettings: $canViewAuditLog
                || $canManageWorkspace
                || $canDeleteWorkspace
                || $canManageMembers
                || $canManageRoles
                || $canInviteMembers,
            createProjects: $canCreateProjects,
            manageWorkspace: $canManageWorkspace,
            manageMembers: $canManageMembers,
            manageRoles: $canManageRoles,
            inviteMembers: $canInviteMembers,
            grantedPermissions: $workspace->grantedPermissionsFor($user),
        );
    }
}
