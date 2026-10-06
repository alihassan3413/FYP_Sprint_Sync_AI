import { describe, expect, it } from 'vitest';

import { applyPending, elapsedSeconds, formatClock, formatDuration, lunchSeconds, projectTimer } from '../src/shared/time';
import type { PendingOperation } from '../src/shared/types';
import { serverTimer } from './fixtures';

const at = (time: string) => Date.parse(`2026-10-12T${time}Z`);
const op = (action: PendingOperation['action'], time: string, session = serverTimer().public_id): PendingOperation => ({
    id: `op-${action}-${time}`,
    action,
    workspace: 'sprintsync',
    session,
    occurredAt: new Date(at(time)).toISOString(),
    createdAt: new Date(at(time)).toISOString(),
});

describe('elapsed time', () => {
    it('adds the running stretch to finished ones', () => {
        expect(elapsedSeconds(serverTimer({ accumulated_seconds: 3600, running_since: '2026-10-12T10:00:00Z' }), at('10:12:31'), 0)).toBe(4351);
    });

    it('does not count paused time', () => {
        expect(elapsedSeconds(serverTimer({ state: 'paused', running_since: null, accumulated_seconds: 6138 }), at('18:00:00'), 0)).toBe(6138);
    });

    it("uses SprintSync's clock when this computer's clock is off", () => {
        /* This computer is 5 minutes slow. */
        expect(elapsedSeconds(serverTimer(), at('09:55:00'), 5 * 60_000)).toBe(3600);
    });

    it('formats the clock and durations', () => {
        expect(formatClock(6138)).toBe('01:42:18');
        expect(formatClock(-5)).toBe('00:00:00');
        expect(formatDuration(27120)).toBe('7h 32m');
        expect(elapsedSeconds(null, at('10:00:00'), 0)).toBe(0);
    });
});

describe('changes waiting to sync', () => {
    it('shows a pause pressed offline straight away', () => {
        const paused = applyPending(serverTimer(), op('pause', '10:00:00'));

        expect(paused?.state).toBe('paused');
        expect(paused?.accumulated_seconds).toBe(3600);
        expect(elapsedSeconds(paused, at('12:00:00'), 0)).toBe(3600);
    });

    it('applies queued changes in order and a stop clears the timer', () => {
        expect(projectTimer(serverTimer(), [op('pause', '10:00:00'), op('resume', '10:30:00')])).toMatchObject({ state: 'running', accumulated_seconds: 3600 });
        expect(projectTimer(serverTimer(), [op('pause', '10:00:00'), op('stop', '10:10:00')])).toBeNull();
    });

    it('ignores changes for another timer or that do not fit', () => {
        expect(applyPending(serverTimer(), op('stop', '10:00:00', 'another-timer'))).toEqual(serverTimer());
        expect(applyPending(serverTimer(), op('resume', '10:00:00'))).toEqual(serverTimer());
        expect(applyPending(null, op('stop', '10:00:00'))).toBeNull();
    });
});

describe('lunch and idle shown before they sync', () => {
    it('lunch stops the work clock and starts the lunch clock', () => {
        const lunch = applyPending(serverTimer(), op('lunch_start', '13:00:00'));

        expect(lunch).toMatchObject({ state: 'lunch', accumulated_seconds: 14400, lunch_started_at: new Date(at('13:00:00')).toISOString() });
        expect(elapsedSeconds(lunch, at('13:30:00'), 0)).toBe(14400);
        expect(lunchSeconds(lunch, at('13:30:00'), 0)).toBe(1800);
        expect(applyPending(lunch, op('lunch_end', '14:00:00'))).toMatchObject({ state: 'running', lunch_started_at: null });
    });

    it('an idle pause counts work only up to the last activity', () => {
        const idle = applyPending(serverTimer(), { ...op('idle_pause', '10:05:00'), idleSince: new Date(at('10:00:00')).toISOString() });

        expect(idle).toMatchObject({ state: 'paused', pause_reason: 'idle', accumulated_seconds: 3600 });
    });
});
