/**
 * Archive-related types and pure helpers, mirroring `lib/tasks.ts`/`lib/meetings.ts`.
 */

export type ArchiveRecordType = 'task' | 'meeting';

export interface ArchiveRecord {
    id: string;
    type: ArchiveRecordType;
    title: string;
    subtitle: string | null;
    project_id: number;
    project_name: string;
    assignee_id: number | null;
    assignee_name: string | null;
    /** ISO datetime */
    occurred_at: string;
    url: string;
}

export interface ArchiveProjectOption {
    id: number;
    name: string;
}

export interface ArchiveAssigneeOption {
    id: number;
    name: string;
}

export interface ArchiveFilters {
    q: string;
    type: '' | ArchiveRecordType;
    project_id: number | null;
    assignee_id: number | null;
    from: string;
    to: string;
}

export interface ArchivePage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export function formatOccurredAt(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

export function archiveTypeLabel(type: ArchiveRecordType): string {
    return type === 'task' ? 'Completed task' : 'Past meeting';
}
