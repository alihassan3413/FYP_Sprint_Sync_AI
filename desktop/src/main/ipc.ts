import { ipcMain, type IpcMainInvokeEvent } from 'electron';

import type { Tracker } from './tracker';

/**
 * The only things the window can ask for. Every call checks that it came
 * from the app's own page and validates its arguments; nothing here can read
 * files, run commands or see the sign-in token.
 */
export function registerIpc(tracker: Tracker, isTrustedSender: (event: IpcMainInvokeEvent) => boolean, actions: { openWeb(): void; setLaunchAtLogin(enabled: boolean): void }): void {
    const handle = (channel: string, run: (...args: unknown[]) => unknown) => {
        ipcMain.handle(channel, (event, ...args: unknown[]) => {
            if (!isTrustedSender(event)) {
                throw new Error('Not allowed.');
            }

            return run(...args);
        });
    };

    const text = (value: unknown, max: number): string => {
        if (typeof value !== 'string' || value.length > max) throw new Error('Invalid input.');

        return value;
    };
    const optionalId = (value: unknown): string | null => (value === null || value === undefined || value === '' ? null : text(value, 64));

    handle('state:get', () => tracker.getState());
    handle('auth:sign-in', (email, password) => tracker.signIn(text(email, 254), text(password, 255)));
    handle('auth:sign-out', () => tracker.signOut());
    handle('workspace:select', (slug) => tracker.selectWorkspace(text(slug, 120)));
    handle('selection:set', (project, task) => tracker.select(optionalId(project), optionalId(task)));
    handle('timer:start', () => tracker.start());
    handle('timer:pause', () => tracker.pause());
    handle('timer:resume', () => tracker.resume());
    handle('timer:stop', () => tracker.stop());
    handle('timer:lunch-start', () => tracker.startLunch());
    handle('timer:lunch-end', () => tracker.endLunch());
    handle('idle:keep', (idlePeriod, reason) => tracker.keepIdle(text(idlePeriod, 64), text(reason, 500)));
    handle('idle:drop', (idlePeriod) => tracker.dropIdle(text(idlePeriod, 64)));
    handle('app:refresh', () => tracker.sync());
    handle('notice:dismiss', () => tracker.dismissNotice());
    handle('app:open-web', () => actions.openWeb());
    handle('prefs:launch-at-login', (enabled) => {
        if (typeof enabled !== 'boolean') throw new Error('Invalid input.');
        actions.setLaunchAtLogin(enabled);
    });
}
