import type { SprintSyncBridge } from './index';

declare global {
    interface Window {
        sprintsync: SprintSyncBridge;
    }
}

export {};
