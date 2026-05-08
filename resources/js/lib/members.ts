/**
 * Member-related types and pure helpers.
 *
 * Lives in `lib/` so any page or component can import without going through
 * a Vue file. Pure functions only — no rendering concerns here.
 */

export type MemberStatus = 'active' | 'away' | 'offline' | 'pending' | 'suspended';
export type MemberRole = 'owner' | 'admin' | 'member' | 'guest' | 'billing';

export interface Member {
    id: number;
    name: string;
    email: string;
    role: MemberRole;
    status: MemberStatus;
    /** ISO datetime — when they were last active */
    last_active_at?: string | null;
    /** Optional avatar url */
    avatar_url?: string | null;
    /** True if this row is the current user */
    is_self?: boolean;
}

/**
 * Map a member status to the AppAvatar `status` prop.
 * Pending/suspended don't get a presence dot.
 */
export function memberPresence(status: MemberStatus): 'active' | 'away' | 'offline' | null {
    if (status === 'active' || status === 'away' || status === 'offline') return status;
    return null;
}

/**
 * Format an ISO datetime as a relative "last active" label.
 */
export function formatLastActive(iso?: string | null, status?: MemberStatus): string {
    if (status === 'pending') return 'Invite pending';
    if (!iso) return '—';

    const then = new Date(iso).getTime();
    const diff = Math.max(0, Date.now() - then);

    const min = Math.floor(diff / 60_000);
    if (min < 1) return 'Just now';
    if (min < 60) return `${min} min ago`;

    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr} hour${hr === 1 ? '' : 's'} ago`;

    const d = Math.floor(hr / 24);
    if (d === 1) return 'Yesterday';
    if (d < 7) return `${d} days ago`;

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
