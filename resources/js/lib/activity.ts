/**
 * Workspace activity types.
 *
 * Every event the app records (invites, role changes, member joins, etc.)
 * gets normalized into this shape so the timeline can render it uniformly.
 */

import type { Member } from '@/lib/members';

export type ActivityKind =
  | 'workspace.created'
  | 'member.invited'
  | 'member.joined'
  | 'member.role_changed'
  | 'member.removed'
  | 'invitation.revoked'
  | 'invitation.expired';

export interface ActivityEntry {
  id: number | string;
  kind: ActivityKind;
  /** ISO datetime */
  occurred_at: string;
  /** The person who took the action */
  actor?: Pick<Member, 'name' | 'email' | 'avatar_url'> | null;
  /** Whether the actor is the current user — render "You" instead of name */
  actor_is_self?: boolean;
  /** Free-form context; shape varies by kind */
  context?: {
    target_email?: string;
    target_name?: string;
    role_from?: string;
    role_to?: string;
    workspace_name?: string;
  };
}

/**
 * Greeting for the dashboard hero — adapts to time of day.
 */
export function greeting(date = new Date()): string {
  const h = date.getHours();
  if (h < 5) return 'Good evening';
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

/**
 * Format a date as "Thursday, May 7" — used in the dashboard eyebrow.
 */
export function formatDateEyebrow(date = new Date()): string {
  return date.toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
  });
}

/**
 * Days since a workspace (or anything) was created.
 */
export function daysSince(iso: string): number {
  const then = new Date(iso).getTime();
  const diff = Date.now() - then;
  return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)));
}
