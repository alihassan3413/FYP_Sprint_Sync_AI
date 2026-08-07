export type ProjectData = {
    id: number;
    name: string;
    description?: string;
    workspace_id: number;
    created_at: string;
    updated_at: string;
};
export type StoreProjectData = {
    name: string;
    description?: string;
};
export type StoreWorkspaceRoleData = {
    name: string;
    slug: string;
    permissions?: { [key: string]: boolean } | null;
};
export enum UserRole {
    OWNER = 'owner',
    ADMIN = 'admin',
    MEMBER = 'member',
}
export type WorkspaceData = {
    name: string;
    slug: string;
    settings: { [key: string]: any } | null;
    is_active: boolean;
};
export type WorkspaceInvitationData = {
    email: string;
    role: string;
};
export enum WorkspacePermission {
    ProjectsView = 'projects.view',
    ProjectsCreate = 'projects.create',
    ProjectsDelete = 'projects.delete',
    MembersInvite = 'members.invite',
    MembersRemove = 'members.remove',
    MembersRoles = 'members.roles',
    BillingView = 'billing.view',
    BillingManage = 'billing.manage',
    IntegrationsView = 'integrations.view',
    IntegrationsManage = 'integrations.manage',
    IntegrationsDeploy = 'integrations.deploy',
}
export type WorkspaceRoleData = {
    id?: number;
    name: string;
    slug?: string;
    permissions?: { [key: string]: boolean } | null;
    workspace_id?: number;
    member_count?: number;
};
