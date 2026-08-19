<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

final class WorkspaceCapabilities
{
    /**
     * @param  array<int, string>  $grantedPermissions
     */
    public function __construct(
        public readonly bool $viewProjects,
        public readonly bool $viewTeam,
        public readonly bool $viewAnalytics,
        public readonly bool $viewArchive,
        public readonly bool $viewAudit,
        public readonly bool $viewWorkspaceSettings,
        public readonly bool $createProjects,
        public readonly bool $manageWorkspace,
        public readonly bool $manageMembers,
        public readonly bool $manageRoles,
        public readonly bool $inviteMembers,
        public readonly array $grantedPermissions,
    ) {}

    /**
     * @return array<string, bool>
     */
    public function navigation(): array
    {
        return [
            'projects' => $this->viewProjects,
            'team' => $this->viewTeam,
            'analytics' => $this->viewAnalytics,
            'archive' => $this->viewArchive,
            'audit' => $this->viewAudit,
            'workspaceSettings' => $this->viewWorkspaceSettings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forDashboard(): array
    {
        return [
            'canInviteMembers' => $this->inviteMembers,
            'canCreateProjects' => $this->createProjects,
            'canManageWorkspace' => $this->manageWorkspace,
            'canManageMembers' => $this->manageMembers,
            'canManageRoles' => $this->manageRoles,
            'canViewAnalytics' => $this->viewAnalytics,
            'canViewAudit' => $this->viewAudit,
            'permissions' => $this->grantedPermissions,
        ];
    }
}
