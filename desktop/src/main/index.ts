import { hostname } from 'node:os';

import { app, BrowserWindow, dialog, net, Notification, powerMonitor, safeStorage, session, shell } from 'electron';

import { SprintSyncApi } from './api';
import { resolveChannel, resolveServerUrl } from './config';
import { registerIpc } from './ipc';
import { LocalStore } from './local-store';
import { SafeStorageSecretStore } from './secure-store';
import { Tracker, type Device } from './tracker';
import { TrackerTray } from './tray';
import { createWindow, isAppUrl } from './window';

/*
 * SprintSync Desktop, main process. It alone talks to SprintSync, holds the
 * sign-in token and keeps the offline queue; the window only shows state and
 * sends button presses through the narrow bridge in preload.
 */

const TICK_MS = 15_000;

/** How often idle time is checked while idle detection is on. */
const IDLE_CHECK_MS = 5_000;

/* Sandbox every renderer (Chromium's OS-level sandbox), before anything starts. */
app.enableSandbox();

if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    void app.whenReady().then(main);
}

function device(): Device {
    const platform = process.platform === 'darwin' ? 'macos' : process.platform === 'win32' ? 'windows' : 'linux';
    /*
     * The computer's name as the OS reports it ("Alis MacBook Pro"); nothing
     * hardware-identifying. Some machines report an IP address instead, which
     * says nothing useful and shouldn't be stored, so those become "Mac" etc.
     */
    const reported = hostname().replace(/\.(local|lan|home)$/i, '').replace(/-/g, ' ').trim();
    const looksLikeAddress = /^[\d.]+$/.test(reported) || reported.includes(':');
    const name = (looksLikeAddress || reported === '' ? { macos: 'Mac', windows: 'Windows PC', linux: 'Linux computer' }[platform] : reported).slice(0, 100);

    return { name, platform };
}

async function main(): Promise<void> {
    const rendererUrl = !app.isPackaged ? process.env.ELECTRON_RENDERER_URL : undefined;
    let serverUrl: string;

    try {
        serverUrl = resolveServerUrl(import.meta.env.MAIN_VITE_SPRINTSYNC_URL, app.isPackaged, import.meta.env.MAIN_VITE_SPRINTSYNC_LOCAL_UAT === '1');
    } catch (error) {
        dialog.showErrorBox('SprintSync', error instanceof Error ? error.message : 'SprintSync is not configured.');
        app.exit(1);

        return;
    }

    lockDown(rendererUrl, serverUrl);

    /* One version source: package.json (app.getVersion()), plus the build's channel. */
    const appInfo = { version: app.getVersion(), channel: resolveChannel(import.meta.env.MAIN_VITE_SPRINTSYNC_CHANNEL) };
    app.setAboutPanelOptions({
        applicationName: 'SprintSync',
        applicationVersion: appInfo.version,
        version: appInfo.channel === 'uat' ? 'UAT build' : '',
        copyright: 'Copyright © 2026 SprintSync',
    });
    /* Windows: notifications and the taskbar say SprintSync (matches appId). */
    if (process.platform === 'win32') app.setAppUserModelId('com.sprintsync.desktop');

    const folder = app.getPath('userData');
    const secrets = SafeStorageSecretStore.inFolder(safeStorage, folder, process.platform);
    const store = LocalStore.inFolder(folder);
    const launchAtLoginSupported = process.platform === 'darwin' || process.platform === 'win32';
    const api = new SprintSyncApi(serverUrl, (input, init) => net.fetch(input, init), () => secrets.read());

    const tracker = new Tracker(
        api,
        store,
        secrets,
        device(),
        {
            notify: (title, body) => {
                if (Notification.isSupported()) new Notification({ title, body, silent: true }).show();
            },
        },
        serverUrl,
        launchAtLoginSupported,
        appInfo,
    );

    let window: BrowserWindow | null = null;
    let quitting = false;

    const show = () => {
        if (!window || window.isDestroyed()) {
            window = createWindow(rendererUrl, !app.isPackaged);
            /* Closing the window keeps tracking in the tray; it never stops the timer. */
            window.on('close', (event) => {
                if (!quitting) {
                    event.preventDefault();
                    window?.hide();
                }
            });
        }

        window.show();
        window.focus();
    };

    const quit = async () => {
        const state = tracker.getState();

        if (state.timer || state.pending > 0) {
            const { response } = await dialog.showMessageBox({
                type: 'info',
                buttons: ['Quit', 'Cancel'],
                defaultId: 1,
                cancelId: 1,
                message: state.timer ? 'Your timer keeps running in SprintSync after you quit.' : 'Some timer changes haven\'t synced yet.',
                detail: state.pending > 0 ? 'They are saved on this computer and will sync next time you open SprintSync.' : 'Open SprintSync again any time to pause or stop it.',
            });

            if (response !== 0) return;
        }

        quitting = true;
        app.quit();
    };

    registerIpc(tracker, (event) => isAppUrl(event.senderFrame?.url ?? '', rendererUrl), {
        openWeb: () => {
            const workspace = tracker.getState().workspace;
            /* Only ever SprintSync itself, built here from trusted parts. */
            void shell.openExternal(workspace ? `${serverUrl}/${encodeURIComponent(workspace)}/time` : serverUrl);
        },
        setLaunchAtLogin: (enabled) => {
            if (!launchAtLoginSupported) return;
            app.setLoginItemSettings({ openAtLogin: enabled });
            tracker.setLaunchAtLogin(enabled);
        },
    });

    const tray = new TrackerTray(tracker, { show, quit: () => void quit() });

    tracker.subscribe((state) => {
        tray.update();
        if (window && !window.isDestroyed()) window.webContents.send('state', state);
    });

    app.on('second-instance', show);
    app.on('activate', show);
    app.on('before-quit', (event) => {
        if (!quitting) {
            event.preventDefault();
            void quit();
        }
    });
    app.on('window-all-closed', () => {
        /* Stay in the tray: the timer lives on the server and in the menu bar. */
    });

    powerMonitor.on('resume', () => void tracker.sync());
    app.on('browser-window-focus', () => void tracker.sync());
    setInterval(() => void tracker.tick(), TICK_MS);

    /*
     * Inactivity: the OS idle clock (seconds since the computer was last
     * used) is read only when the workspace turned idle detection or activity
     * integrity on, and only while a timer runs. Nothing else is read.
     */
    setInterval(() => {
        const { policy, timer } = tracker.getState();

        if (timer?.state === 'running' && policy?.idle_enabled) {
            void tracker.checkIdle(powerMonitor.getSystemIdleTime());
        }
    }, IDLE_CHECK_MS);
    setInterval(() => {
        const { policy, timer } = tracker.getState();

        if (timer?.state === 'running' && policy?.integrity_enabled) {
            tracker.sampleActivity(powerMonitor.getSystemIdleTime());
        } else {
            tracker.sampleActivity(0);
        }
    }, 1_000);
    setInterval(() => tray.update(), 30_000);

    show();
    await tracker.init();
}

/**
 * App-wide guards: the page may not navigate, open windows, ask for
 * permissions (camera, microphone, notifications...) or attach webviews. The
 * main process may only talk to SprintSync over the configured address.
 */
function lockDown(rendererUrl: string | undefined, serverUrl: string): void {
    session.defaultSession.setPermissionRequestHandler((_webContents, _permission, callback) => callback(false));
    session.defaultSession.setPermissionCheckHandler(() => false);

    app.on('web-contents-created', (_event, contents) => {
        contents.on('will-navigate', (event, url) => {
            if (!isAppUrl(url, rendererUrl)) event.preventDefault();
        });
        contents.on('will-redirect', (event, url) => {
            if (!isAppUrl(url, rendererUrl)) event.preventDefault();
        });
        contents.on('will-attach-webview', (event) => event.preventDefault());
        contents.setWindowOpenHandler(({ url }) => {
            /* Links to SprintSync itself open in the browser; nothing else opens at all. */
            if (url.startsWith(`${serverUrl}/`)) void shell.openExternal(url);

            return { action: 'deny' };
        });
    });
}
