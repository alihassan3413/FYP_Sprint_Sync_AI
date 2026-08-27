import type { CommentAttachment } from '@/lib/attachments';

/**
 * Task-related types and pure helpers, mirroring `lib/projects.ts`.
 */

export interface Task {
    id: number;
    title: string;
    description: string | null;
    board_column_id: number;
    /** ISO date (YYYY-MM-DD), no time component */
    due_date: string | null;
    project_id: number;
    sprint_id: number | null;
    workspace_id: number;
    assigned_to: number | null;
    assignee_name: string | null;
    comments: TaskComment[];
    attachments: CommentAttachment[];
    /** ISO datetime */
    created_at: string;
    /** ISO datetime */
    updated_at: string;
}

export interface TaskComment {
    id: number;
    body: string;
    task_id: number;
    user_id: number;
    user_name: string;
    /** ISO datetime */
    created_at: string;
    attachments: CommentAttachment[];
}

export interface TaskMember {
    id: number;
    name: string;
    email: string;
}

export interface BoardColumn {
    id: number;
    name: string;
    position: number;
    is_default: boolean;
    is_done: boolean;
    project_id: number;
}

export function isOverdue(task: Pick<Task, 'due_date'>, isDone: boolean): boolean {
    if (!task.due_date || isDone) return false;
    return new Date(`${task.due_date}T23:59:59`).getTime() < Date.now();
}

export function formatDueDate(isoDate: string): string {
    return new Date(`${isoDate}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
