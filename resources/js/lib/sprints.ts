export interface Sprint {
    id: number;
    name: string;
    goal: string | null;
    /** ISO date (YYYY-MM-DD) */
    starts_on: string;
    /** ISO date (YYYY-MM-DD) */
    ends_on: string;
    project_id: number;
    is_current: boolean;
    is_upcoming: boolean;
    task_count: number;
}

export function formatSprintRange(sprint: Pick<Sprint, 'starts_on' | 'ends_on'>): string {
    const options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
    const start = new Date(`${sprint.starts_on}T00:00:00`).toLocaleDateString(undefined, options);
    const end = new Date(`${sprint.ends_on}T00:00:00`).toLocaleDateString(undefined, options);

    return `${start} — ${end}`;
}

export function sprintStatusLabel(sprint: Pick<Sprint, 'is_current' | 'is_upcoming'>): string {
    if (sprint.is_current) return 'Current';
    if (sprint.is_upcoming) return 'Upcoming';
    return 'Completed';
}
