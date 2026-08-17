/**
 * Audit log types and pure helpers, mirroring `lib/archive.ts`/`lib/analytics.ts`.
 */

export interface AuditLogEntry {
    id: number;
    actor_name: string | null;
    actor_avatar_url: string | null;
    action_label: string;
    category: string;
    description: string;
    project_name: string | null;
    /** ISO datetime */
    created_at: string;
}

export interface AuditProjectOption {
    id: number;
    name: string;
}

export interface AuditActorOption {
    id: number;
    name: string;
}

export interface AuditFilters {
    user_id: number | null;
    category: string;
    project_id: number | null;
    from: string;
    to: string;
}

export interface AuditPage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export function formatAuditTimestamp(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

const CATEGORY_BADGE_VARIANT: Record<string, 'purple' | 'info' | 'success' | 'warning' | 'neutral'> = {
    Workspace: 'neutral',
    Team: 'info',
    Projects: 'purple',
    Tasks: 'success',
    Meetings: 'warning',
};

export function categoryBadgeVariant(category: string): 'purple' | 'info' | 'success' | 'warning' | 'neutral' {
    return CATEGORY_BADGE_VARIANT[category] ?? 'neutral';
}
