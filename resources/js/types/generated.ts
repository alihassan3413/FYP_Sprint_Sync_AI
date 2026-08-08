export type ArchiveRecordData = {
    id: string;
    type: string;
    title: string;
    subtitle?: string;
    project_id: number;
    project_name: string;
    assignee_id?: number;
    assignee_name?: string;
    occurred_at: string;
    url: string;
};
export type BoardColumnData = {
    id: number;
    name: string;
    position: number;
    is_default: boolean;
    is_done: boolean;
    project_id: number;
};
export type MeetingData = {
    id: number;
    title: string;
    description?: string;
    scheduled_at: string;
    duration_minutes: number;
    meeting_link?: string;
    project_id: number;
    workspace_id: number;
    created_by: number;
    creator_name?: string;
    created_at: string;
    updated_at: string;
};
export type ProjectData = {
    id: number;
    name: string;
    description?: string;
    workspace_id: number;
    created_at: string;
    updated_at: string;
};
export enum ProjectRole {
    MANAGER = 'manager',
    MEMBER = 'member',
}
export type StoreMeetingData = {
    title: string;
    description?: string;
    scheduled_at: string;
    duration_minutes: number;
    meeting_link?: string;
};
export type StoreProjectData = {
    name: string;
    description?: string;
};
export type StoreTaskData = {
    title: string;
    description?: string;
    assigned_to?: number;
    due_date?: string;
};
export type StoreWorkspaceRoleData = {
    name: string;
    slug: string;
    permissions?: { [key: string]: boolean } | null;
};
export type TaskCommentData = {
    id: number;
    body: string;
    task_id: number;
    user_id: number;
    user_name: string;
    created_at: string;
};
export type TaskData = {
    id: number;
    title: string;
    description?: string;
    board_column_id: number;
    due_date?: string;
    project_id: number;
    workspace_id: number;
    assigned_to?: number;
    assignee_name?: string;
    comments: Array<TaskCommentData>;
    created_at: string;
    updated_at: string;
};
export type UpdateTaskStatusData = {
    board_column_id: number;
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
