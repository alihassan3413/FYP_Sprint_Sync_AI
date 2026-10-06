import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import type { ServerTimer } from '../src/shared/types';

export function tempFolder(): string {
    return mkdtempSync(join(tmpdir(), 'sprintsync-desktop-'));
}

export function serverTimer(change: Partial<ServerTimer> = {}): ServerTimer {
    return {
        public_id: '01k0000000000000000000000a',
        state: 'running',
        project: { public_id: '01k0000000000000000000000p', name: 'RocketFlood CSR' },
        task: { public_id: '01k0000000000000000000000t', title: 'Flood Quote Workflow' },
        started_at: '2026-10-12T09:00:00+00:00',
        running_since: '2026-10-12T09:00:00+00:00',
        lunch_started_at: null,
        pause_reason: null,
        accumulated_seconds: 0,
        elapsed_seconds: 0,
        longest_segment_seconds: 0,
        billable: true,
        note: null,
        server_time: '2026-10-12T09:00:00+00:00',
        entry_public_id: null,
        ...change,
    };
}
