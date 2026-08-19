export type AnalyticsData = {
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    task_completion_percentage: number;
    overdue_tasks: number;
    tasks_by_column: Array<TaskColumnBreakdownData>;
    tasks_by_assignee: Array<TaskAssigneeBreakdownData>;
    total_meetings: number;
    upcoming_meetings: number;
    past_meetings: number;
    total_projects: number;
    projects: Array<ProjectSummaryData>;
    sprint_progress: SprintProgressData;
    scope: string;
};
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
export enum AuditAction {
    WORKSPACE_CREATED = 'workspace.created',
    WORKSPACE_RENAMED = 'workspace.renamed',
    WORKSPACE_DELETED = 'workspace.deleted',
    MEMBER_INVITED = 'member.invited',
    MEMBER_REMOVED = 'member.removed',
    MEMBER_ROLE_CHANGED = 'member.role_changed',
    INVITE_LINK_GENERATED = 'invite_link.generated',
    INVITE_LINK_REVOKED = 'invite_link.revoked',
    INVITE_LINK_JOINED = 'invite_link.joined',
    PROJECT_CREATED = 'project.created',
    PROJECT_UPDATED = 'project.updated',
    PROJECT_DELETED = 'project.deleted',
    PROJECT_MEMBER_ADDED = 'project.member_added',
    PROJECT_MEMBER_REMOVED = 'project.member_removed',
    PROJECT_MEMBER_ROLE_CHANGED = 'project.member_role_changed',
    TASK_CREATED = 'task.created',
    TASK_UPDATED = 'task.updated',
    TASK_DELETED = 'task.deleted',
    TASK_MOVED = 'task.moved',
    TASK_ASSIGNED = 'task.assigned',
    SPRINT_CREATED = 'sprint.created',
    SPRINT_UPDATED = 'sprint.updated',
    SPRINT_DELETED = 'sprint.deleted',
    BOARD_COLUMN_CREATED = 'board_column.created',
    BOARD_COLUMN_DELETED = 'board_column.deleted',
    BOARD_COLUMN_REORDERED = 'board_column.reordered',
    MEETING_SCHEDULED = 'meeting.scheduled',
    MEETING_UPDATED = 'meeting.updated',
    MEETING_CANCELLED = 'meeting.cancelled',
    ACCOUNT_PROFILE_UPDATED = 'account.profile_updated',
    ACCOUNT_PASSWORD_CHANGED = 'account.password_changed',
    ACCOUNT_AVATAR_UPDATED = 'account.avatar_updated',
    ACCOUNT_AVATAR_REMOVED = 'account.avatar_removed',
    ACCOUNT_DELETED = 'account.deleted',
}
export type AuditLogEntryData = {
    id: number;
    actor_name?: string;
    actor_avatar_url?: string;
    action_label: string;
    category: string;
    description: string;
    project_name?: string;
    created_at: string;
};
export type BoardColumnData = {
    id: number;
    name: string;
    position: number;
    is_default: boolean;
    is_done: boolean;
    project_id: number;
};
export type DashboardMeetingData = {
    id: number;
    title: string;
    project_id: number;
    project_name: string;
    scheduled_at: string;
    duration_minutes: number;
    join_url?: string;
    is_past: boolean;
    url: string;
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
    join_url?: string;
    transcript?: { [key: string]: any } | null;
    participants: { [key: number]: { [key: string]: any } };
    created_at: string;
    updated_at: string;
};
export enum NotificationChannel {
    IN_APP = 'in_app',
    EMAIL = 'email',
}
export enum NotificationType {
    MEETING_SCHEDULED = 'meeting_scheduled',
    MEETING_UPDATED = 'meeting_updated',
    MEETING_CANCELLED = 'meeting_cancelled',
    TASK_ASSIGNED = 'task_assigned',
    TASK_MOVED = 'task_moved',
    TASK_COMMENT = 'task_comment',
}
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
export type ProjectSummaryData = {
    id: number;
    name: string;
    total_tasks: number;
    completed_tasks: number;
    completion_percentage: number;
};
export type SprintData = {
    id: number;
    name: string;
    goal?: string;
    starts_on: string;
    ends_on: string;
    project_id: number;
    is_current: boolean;
    is_upcoming: boolean;
    task_count: number;
};
export type SprintProgressData = {
    has_sprint: boolean;
    sprints: Array<SprintRefData>;
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    completion_percentage: number;
    tasks_by_column: Array<TaskColumnBreakdownData>;
};
export type SprintRefData = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    project_id: number;
    project_name: string;
};
export type StoreMeetingData = {
    title: string;
    description?: string;
    scheduled_at: string;
    duration_minutes: number;
    meeting_link?: string;
    participant_user_ids: { [key: number]: number };
    participant_emails: { [key: number]: string };
};
export type StoreProjectData = {
    name: string;
    description?: string;
};
export type StoreSprintData = {
    name: string;
    goal?: string;
    starts_on: string;
    ends_on: string;
};
export type StoreTaskData = {
    title: string;
    description?: string;
    assigned_to?: number;
    due_date?: string;
    sprint_id?: number;
};
export type StoreWorkspaceRoleData = {
    name: string;
    slug: string;
    permissions?: { [key: string]: boolean } | null;
};
export type TaskAssigneeBreakdownData = {
    assignee_id?: number;
    name: string;
    count: number;
};
export type TaskColumnBreakdownData = {
    name: string;
    is_done: boolean;
    count: number;
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
    sprint_id?: number;
    workspace_id: number;
    assigned_to?: number;
    assignee_name?: string;
    comments: Array<TaskCommentData>;
    created_at: string;
    updated_at: string;
};
export enum TranscriptSource {
    Recording = 'recording',
    Manual = 'manual',
}
export enum TranscriptStatus {
    AwaitingAudio = 'awaiting_audio',
    Queued = 'queued',
    Processing = 'processing',
    Completed = 'completed',
    Failed = 'failed',
}
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
