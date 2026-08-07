/**
 * Task-related types and pure helpers, mirroring `lib/projects.ts`.
 */

export type TaskStatusValue = 'todo' | 'in_progress' | 'done';

export interface Task {
    id: number;
    title: string;
    description: string | null;
    status: TaskStatusValue;
    /** ISO date (YYYY-MM-DD), no time component */
    due_date: string | null;
    project_id: number;
    workspace_id: number;
    assigned_to: number | null;
    assignee_name: string | null;
    /** ISO datetime */
    created_at: string;
    /** ISO datetime */
    updated_at: string;
}

export interface TaskMember {
    id: number;
    name: string;
    email: string;
}

export const TASK_STATUSES: { value: TaskStatusValue; label: string }[] = [
    { value: 'todo', label: 'To Do' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'done', label: 'Done' },
];

export function taskStatusLabel(status: TaskStatusValue): string {
    return TASK_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export function isOverdue(task: Pick<Task, 'due_date' | 'status'>): boolean {
    if (!task.due_date || task.status === 'done') return false;
    return new Date(`${task.due_date}T23:59:59`).getTime() < Date.now();
}

export function formatDueDate(isoDate: string): string {
    return new Date(`${isoDate}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
