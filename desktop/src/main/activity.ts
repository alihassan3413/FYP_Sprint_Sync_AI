/**
 * Activity integrity sampling, only while the workspace has it on and a
 * timer is running. The only input is the OS idle clock (seconds since the
 * computer was last used), read once a second. When it goes back to zero, the
 * computer was used: that moment is kept in memory. Nothing about which keys,
 * where the mouse was, or which app or site is ever read.
 *
 * Every WINDOW_MS the gaps between those moments become three numbers (how
 * many resets, the average gap, how regular the gaps were) and are sent to
 * SprintSync, which decides with its own thresholds whether it looks
 * machine-made. The individual moments never leave this computer.
 */
export const WINDOW_MS = 10 * 60_000;

export interface ActivityWindow {
    windowStart: number;
    windowEnd: number;
    resets: number;
    meanGapMs: number;
    /** Coefficient of variation of the gaps, in basis points (100 = 1%). */
    variationBp: number;
}

export class ActivityMonitor {
    private resets: number[] = [];
    private lastIdle: number | null = null;
    private windowStart: number | null = null;

    /** Feed one reading of the OS idle clock (seconds). Returns a finished window, if one is due. */
    sample(idleSeconds: number, nowMs: number): ActivityWindow | null {
        this.windowStart ??= nowMs;

        if (this.lastIdle !== null && idleSeconds < this.lastIdle) {
            this.resets.push(nowMs - idleSeconds * 1000);
        }

        this.lastIdle = idleSeconds;

        if (nowMs - this.windowStart < WINDOW_MS) {
            return null;
        }

        const window = summarise(this.resets, this.windowStart, nowMs);
        this.resets = [];
        this.windowStart = nowMs;

        return window;
    }

    /** Timer stopped or integrity turned off: forget everything. */
    reset(): void {
        this.resets = [];
        this.lastIdle = null;
        this.windowStart = null;
    }
}

export function summarise(resets: number[], windowStart: number, windowEnd: number): ActivityWindow {
    const gaps = resets.slice(1).map((time, index) => time - resets[index]!);

    if (gaps.length === 0) {
        return { windowStart, windowEnd, resets: resets.length, meanGapMs: 0, variationBp: 0 };
    }

    const mean = gaps.reduce((sum, gap) => sum + gap, 0) / gaps.length;
    const deviation = Math.sqrt(gaps.reduce((sum, gap) => sum + (gap - mean) ** 2, 0) / gaps.length);

    return {
        windowStart,
        windowEnd,
        resets: resets.length,
        meanGapMs: Math.round(mean),
        variationBp: mean === 0 ? 0 : Math.round((deviation / mean) * 10_000),
    };
}
