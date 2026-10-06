import { contextBridge, ipcRenderer, type IpcRendererEvent } from 'electron';

import type { AppState } from '../shared/types';

/**
 * The window's only way to reach the app: a fixed list of actions. No
 * ipcRenderer, Node or Electron objects are exposed, and nothing here can
 * see the sign-in token.
 */
const api = {
    getState: (): Promise<AppState> => ipcRenderer.invoke('state:get'),
    onState: (listener: (state: AppState) => void): (() => void) => {
        const handler = (_event: IpcRendererEvent, state: AppState) => listener(state);
        ipcRenderer.on('state', handler);

        return () => ipcRenderer.removeListener('state', handler);
    },
    signIn: (email: string, password: string): Promise<void> => ipcRenderer.invoke('auth:sign-in', email, password),
    signOut: (): Promise<void> => ipcRenderer.invoke('auth:sign-out'),
    selectWorkspace: (slug: string): Promise<void> => ipcRenderer.invoke('workspace:select', slug),
    select: (project: string | null, task: string | null): Promise<void> => ipcRenderer.invoke('selection:set', project, task),
    start: (): Promise<void> => ipcRenderer.invoke('timer:start'),
    pause: (): Promise<void> => ipcRenderer.invoke('timer:pause'),
    resume: (): Promise<void> => ipcRenderer.invoke('timer:resume'),
    stop: (): Promise<void> => ipcRenderer.invoke('timer:stop'),
    startLunch: (): Promise<void> => ipcRenderer.invoke('timer:lunch-start'),
    endLunch: (): Promise<void> => ipcRenderer.invoke('timer:lunch-end'),
    keepIdle: (idlePeriod: string, reason: string): Promise<void> => ipcRenderer.invoke('idle:keep', idlePeriod, reason),
    dropIdle: (idlePeriod: string): Promise<void> => ipcRenderer.invoke('idle:drop', idlePeriod),
    refresh: (): Promise<void> => ipcRenderer.invoke('app:refresh'),
    dismissNotice: (): Promise<void> => ipcRenderer.invoke('notice:dismiss'),
    openWeb: (): Promise<void> => ipcRenderer.invoke('app:open-web'),
    setLaunchAtLogin: (enabled: boolean): Promise<void> => ipcRenderer.invoke('prefs:launch-at-login', enabled),
};

export type SprintSyncBridge = typeof api;

contextBridge.exposeInMainWorld('sprintsync', api);
