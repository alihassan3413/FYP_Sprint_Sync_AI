import { join } from 'node:path';

import type { PendingOperation, ServerTimer, TimePolicy } from '../shared/types';
import { readJson, writeJsonDurably } from './durable-file';

/**
 * Everything kept on this computer besides the sign-in token (which lives in
 * the OS keychain, see secure-store): preferences, the last selection, the
 * last timer seen (for offline display only, never as recorded time) and the
 * queue of timer changes still waiting to reach SprintSync. Every change is
 * written durably before anything is sent.
 */
export interface LocalState {
    workspace: string | null;
    selection: { project: string | null; task: string | null };
    launchAtLogin: boolean;
    lastTimer: ServerTimer | null;
    /** The last time rules SprintSync sent, so idle detection keeps working offline. */
    lastPolicy: TimePolicy | null;
    queue: PendingOperation[];
}

const EMPTY: LocalState = { workspace: null, selection: { project: null, task: null }, launchAtLogin: false, lastTimer: null, lastPolicy: null, queue: [] };

export class LocalStore {
    private state: LocalState;

    constructor(private readonly path: string) {
        const stored = readJson<Partial<LocalState>>(path, {});
        this.state = { ...EMPTY, ...stored, queue: Array.isArray(stored.queue) ? stored.queue : [] };
    }

    static inFolder(folder: string): LocalStore {
        return new LocalStore(join(folder, 'sprintsync-state.json'));
    }

    get(): Readonly<LocalState> {
        return this.state;
    }

    update(change: Partial<LocalState>): void {
        this.state = { ...this.state, ...change };
        writeJsonDurably(this.path, this.state);
    }

    enqueue(operation: PendingOperation): void {
        this.update({ queue: [...this.state.queue, operation] });
    }

    /** Removed only after SprintSync confirmed it (or refused it for good). */
    dequeue(id: string): void {
        this.update({ queue: this.state.queue.filter((operation) => operation.id !== id) });
    }
}
