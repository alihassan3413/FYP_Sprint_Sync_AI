import type { PendingOperation, ServerTimer } from './types';

/**
 * Seconds on the timer at `nowMs` (this computer's clock): finished stretches
 * plus the running one, measured against SprintSync's clock via the offset.
 * For display only; SprintSync decides the recorded duration.
 */
export function elapsedSeconds(timer: ServerTimer | null, nowMs: number, clockOffsetMs: number): number {
    if (!timer) return 0;
    if (timer.state !== 'running' || !timer.running_since) return timer.accumulated_seconds;

    const running = (nowMs + clockOffsetMs - Date.parse(timer.running_since)) / 1000;

    return timer.accumulated_seconds + Math.max(0, Math.floor(running));
}

/** Seconds on lunch so far (display only). */
export function lunchSeconds(timer: ServerTimer | null, nowMs: number, clockOffsetMs: number): number {
    if (!timer || timer.state !== 'lunch' || !timer.lunch_started_at) return 0;

    return Math.max(0, Math.floor((nowMs + clockOffsetMs - Date.parse(timer.lunch_started_at)) / 1000));
}

/** 6138 → "1:42:18". */
export function formatClock(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`;
}

/** 27120 → "7h 32m". */
export function formatDuration(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));

    return `${Math.floor(s / 3600)}h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}m`;
}

/**
 * The timer as it will look once a queued change reaches SprintSync, so the
 * window shows what the person did while offline. Changes for another timer,
 * or ones that don't fit its state, are ignored (SprintSync will refuse them).
 */
export function applyPending(timer: ServerTimer | null, operation: PendingOperation): ServerTimer | null {
    if (!timer || timer.public_id !== operation.session || timer.state === 'stopped') return timer;

    const at = Date.parse(operation.occurredAt);
    const runningFor = timer.state === 'running' && timer.running_since ? Math.max(0, Math.floor((at - Date.parse(timer.running_since)) / 1000)) : 0;

    switch (operation.action) {
        case 'pause':
            return timer.state === 'running' ? { ...timer, state: 'paused', pause_reason: null, running_since: null, accumulated_seconds: timer.accumulated_seconds + runningFor } : timer;
        case 'idle_pause': {
            if (timer.state !== 'running' || !timer.running_since) return timer;
            /* Work stops at the last activity, not when the threshold was reached. */
            const since = Math.max(Date.parse(operation.idleSince ?? operation.occurredAt), Date.parse(timer.running_since));
            const worked = Math.max(0, Math.floor((since - Date.parse(timer.running_since)) / 1000));

            return { ...timer, state: 'paused', pause_reason: 'idle', running_since: null, accumulated_seconds: timer.accumulated_seconds + worked };
        }
        case 'resume':
            return timer.state === 'paused' ? { ...timer, state: 'running', pause_reason: null, running_since: operation.occurredAt } : timer;
        case 'lunch_start':
            return timer.state === 'running' ? { ...timer, state: 'lunch', running_since: null, lunch_started_at: operation.occurredAt, accumulated_seconds: timer.accumulated_seconds + runningFor } : timer;
        case 'lunch_end':
            return timer.state === 'lunch' ? { ...timer, state: 'running', lunch_started_at: null, running_since: operation.occurredAt } : timer;
        case 'stop':
            return null;
    }
}

/** The timer with every queued change applied, in order. */
export function projectTimer(timer: ServerTimer | null, queue: PendingOperation[]): ServerTimer | null {
    return queue.reduce<ServerTimer | null>((current, operation) => applyPending(current, operation), timer);
}
