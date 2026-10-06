import { describe, expect, it } from 'vitest';

import { ActivityMonitor, summarise, WINDOW_MS } from '../src/main/activity';

describe('activity summary', () => {
    it('a fixed beat is perfectly regular', () => {
        const resets = Array.from({ length: 21 }, (_, i) => i * 30_000);

        expect(summarise(resets, 0, WINDOW_MS)).toMatchObject({ resets: 21, meanGapMs: 30_000, variationBp: 0 });
    });

    it('human use is irregular', () => {
        const gaps = [800, 1200, 45_000, 900, 15_000, 3000, 120_000, 700];
        const resets = gaps.reduce<number[]>((times, gap) => [...times, (times.at(-1) ?? 0) + gap], [0]);

        expect(summarise(resets, 0, WINDOW_MS).variationBp).toBeGreaterThan(5000);
    });

    it('detects resets from the idle clock alone and forgets them after each window', () => {
        const monitor = new ActivityMonitor();
        let window = null;

        for (let second = 0; second <= 600 && !window; second++) {
            window = monitor.sample(second % 60, second * 1000);
        }

        expect(window).toMatchObject({ resets: 10, meanGapMs: 60_000 });
        monitor.reset();
        expect(monitor.sample(5, 700_000)).toBeNull();
    });
});
