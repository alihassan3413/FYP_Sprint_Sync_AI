/**
 * Meeting-related types and pure helpers, mirroring `lib/tasks.ts`.
 */

export interface MeetingParticipant {
    id: number;
    user_id: number | null;
    email: string;
    name: string | null;
    is_external: boolean;
}

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
    join_url: string | null;
    participants: MeetingParticipant[];
    /** ISO datetime */
    created_at: string;
    /** ISO datetime */
    updated_at: string;
}

export function isPastMeeting(meeting: Pick<Meeting, 'scheduled_at' | 'duration_minutes'>): boolean {
    const end = new Date(meeting.scheduled_at).getTime() + meeting.duration_minutes * 60_000;
    return end < Date.now();
}

export function formatMeetingDate(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', timeZone });
}

export function formatMeetingTime(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZone });
}

export function formatDuration(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return remainder === 0 ? `${hours}h` : `${hours}h ${remainder}m`;
}

export function toDateTimeLocalValue(iso: string, timeZone?: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((entry) => entry.type === type)?.value ?? '00';
    const hour = part('hour') === '24' ? '00' : part('hour');

    return `${part('year')}-${part('month')}-${part('day')}T${hour}:${part('minute')}`;
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
