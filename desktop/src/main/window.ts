import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { BrowserWindow } from 'electron';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * The one small window. Locked down: no Node in the page, an isolated
 * preload bridge, Chromium's sandbox, no navigation away from the app and no
 * pop-ups (see the guards in index.ts).
 */
export function createWindow(rendererUrl: string | undefined, devTools: boolean): BrowserWindow {
    const window = new BrowserWindow({
        width: 380,
        height: 600,
        minWidth: 340,
        minHeight: 480,
        maxWidth: 480,
        show: false,
        title: 'SprintSync',
        backgroundColor: '#f6f7f4',
        autoHideMenuBar: true,
        webPreferences: {
            preload: join(here, '../preload/index.cjs'),
            contextIsolation: true,
            nodeIntegration: false,
            nodeIntegrationInWorker: false,
            sandbox: true,
            webSecurity: true,
            allowRunningInsecureContent: false,
            webviewTag: false,
            spellcheck: false,
            devTools,
        },
    });

    window.once('ready-to-show', () => window.show());

    if (rendererUrl) {
        void window.loadURL(rendererUrl);
    } else {
        void window.loadFile(join(here, '../renderer/index.html'));
    }

    return window;
}

/** Is this URL the app's own page (dev server or the bundled file)? */
export function isAppUrl(url: string, rendererUrl: string | undefined): boolean {
    if (rendererUrl) {
        try {
            return new URL(url).origin === new URL(rendererUrl).origin;
        } catch {
            return false;
        }
    }

    return url.startsWith('file://') && url.includes('/renderer/index.html');
}
