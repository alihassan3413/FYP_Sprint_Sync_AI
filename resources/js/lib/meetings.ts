/**
 * Meeting-related types and pure helpers, mirroring `lib/tasks.ts`.
 */

export interface Meeting {
    id: number;
    title: string;
    description: string | null;
    /** ISO datetime */
    scheduled_at: string;
    duration_minutes: number;
    meeting_link: string | null;
    project_id: number;
    workspace_id: number;
    created_by: number;
    creator_name: string | null;
    /** ISO datetime */
    created_at: string;
    /** ISO datetime */
    updated_at: string;
}

export function isPastMeeting(meeting: Pick<Meeting, 'scheduled_at' | 'duration_minutes'>): boolean {
    const end = new Date(meeting.scheduled_at).getTime() + meeting.duration_minutes * 60_000;
    return end < Date.now();
}

export function formatMeetingDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

export function formatMeetingTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function formatDuration(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return remainder === 0 ? `${hours}h` : `${hours}h ${remainder}m`;
}

export function toDateTimeLocalValue(iso: string): string {
    const date = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function isValidMeetingLink(link: string | null | undefined): link is string {
    if (!link) return false;

    try {
        const url = new URL(link);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}
