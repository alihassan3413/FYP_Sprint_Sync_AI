export type SprintStatus = 'planned' | 'active' | 'completed';

export type SprintHealth = 'not_started' | 'empty' | 'on_track' | 'at_risk' | 'off_track' | 'overdue' | 'done';

export type SprintCarryOver = 'backlog' | 'next_sprint';

export interface Sprint {
    id: number;
    name: string;
    goal: string | null;
    status: SprintStatus;
    status_label: string;
    /** ISO date (YYYY-MM-DD) */
    starts_on: string;
    /** ISO date (YYYY-MM-DD) */
    ends_on: string;
    started_at: string | null;
    completed_at: string | null;
    project_id: number;
    is_current: boolean;
    is_upcoming: boolean;
    is_overdue: boolean;
    task_count: number;
    completed_task_count: number;
    completion_percentage: number;
    time_elapsed_percentage: number;
    total_days: number;
    days_remaining: number;
    committed_task_count: number | null;
    carried_over_task_count: number | null;
}

export interface SprintBurndownPoint {
    date: string;
    remaining: number;
    ideal: number;
}

export interface SprintReport {
    sprint_id: number;
    name: string;
    goal: string | null;
    status: SprintStatus;
    health: SprintHealth;
    health_label: string;
    starts_on: string;
    ends_on: string;
    total_days: number;
    days_elapsed: number;
    days_remaining: number;
    time_elapsed_percentage: number;
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    overdue_tasks: number;
    unassigned_tasks: number;
    completion_percentage: number;
    expected_percentage: number;
    pace_delta: number;
    committed_task_count: number | null;
    scope_added: number;
    carried_over_task_count: number | null;
    average_cycle_time_days: number | null;
    velocity_average: number | null;
    burndown: SprintBurndownPoint[];
    workload: { name: string; total: number; completed: number }[];
    blockers: { id: number; title: string; due_date: string | null; assignee: string | null }[];
    column_breakdown: Record<string, number>;
    recommendations: string[];
    summary: string;
}

export function formatSprintRange(sprint: Pick<Sprint, 'starts_on' | 'ends_on'>): string {
    const options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
    const start = new Date(`${sprint.starts_on}T00:00:00`).toLocaleDateString(undefined, options);
    const end = new Date(`${sprint.ends_on}T00:00:00`).toLocaleDateString(undefined, options);

    return `${start} — ${end}`;
}

/**
 * The lifecycle state is what the team decided; the dates only colour it in.
 */
export function sprintStatusLabel(sprint: Pick<Sprint, 'status_label'>): string {
    return sprint.status_label;
}

/** Tailwind classes for the status pill, keyed by lifecycle state. */
export const sprintStatusStyles: Record<SprintStatus, string> = {
    planned: 'border-border text-muted-foreground',
    active: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    completed: 'border-blue-500/30 text-blue-700 dark:text-blue-400',
};

/** Tailwind classes for the health badge on a running sprint. */
export const sprintHealthStyles: Record<SprintHealth, string> = {
    not_started: 'border-border text-muted-foreground',
    empty: 'border-border text-muted-foreground',
    on_track: 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400',
    at_risk: 'border-amber-500/40 text-amber-700 dark:text-amber-400',
    off_track: 'border-red-500/40 text-red-700 dark:text-red-400',
    overdue: 'border-red-500/40 text-red-700 dark:text-red-400',
    done: 'border-blue-500/30 text-blue-700 dark:text-blue-400',
};

/**
 * "3 days left", "ends today", "4 days over" — the phrase a standup would use.
 */
export function sprintTimingLabel(sprint: Pick<Sprint, 'status' | 'days_remaining' | 'is_overdue'>): string {
    if (sprint.status === 'completed') return 'Closed';
    if (sprint.is_overdue) return 'Past its end date';
    if (sprint.status === 'planned') return 'Not started';
    if (sprint.days_remaining === 0) return 'Ends today';

    return `${sprint.days_remaining} ${sprint.days_remaining === 1 ? 'day' : 'days'} left`;
}
