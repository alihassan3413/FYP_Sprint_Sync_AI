/**
 * Analytics-related types and pure helpers, mirroring `lib/archive.ts`.
 */

export interface TaskColumnBreakdown {
    name: string;
    is_done: boolean;
    count: number;
}

export interface TaskAssigneeBreakdown {
    assignee_id: number | null;
    name: string;
    count: number;
}

export interface ProjectSummary {
    id: number;
    name: string;
    total_tasks: number;
    completed_tasks: number;
    completion_percentage: number;
}

export interface Analytics {
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    task_completion_percentage: number;
    overdue_tasks: number;
    tasks_by_column: TaskColumnBreakdown[];
    tasks_by_assignee: TaskAssigneeBreakdown[];
    total_meetings: number;
    upcoming_meetings: number;
    past_meetings: number;
    total_projects: number;
    projects: ProjectSummary[];
    sprint_progress: SprintProgress;
    scope: 'team' | 'personal';
}

export interface SprintRef {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    project_id: number;
    project_name: string;
}

export interface SprintProgress {
    has_sprint: boolean;
    sprints: SprintRef[];
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    completion_percentage: number;
    tasks_by_column: TaskColumnBreakdown[];
}

export interface AnalyticsSprintOption {
    id: number;
    name: string;
    project_id: number;
    is_current: boolean;
}

export interface AnalyticsProjectOption {
    id: number;
    name: string;
}

export interface AnalyticsFilters {
    project_id: number | null;
    sprint_id: number | null;
    from: string;
    to: string;
}
