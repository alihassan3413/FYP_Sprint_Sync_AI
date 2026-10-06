import { readFileSync } from 'node:fs';

import { Menu, nativeImage, Tray } from 'electron';

import colourIcon from '../../resources/tray/tray.png?asset';
import colourIcon2x from '../../resources/tray/tray@2x.png?asset';
import templateIcon from '../../resources/tray/trayTemplate.png?asset';
import templateIcon2x from '../../resources/tray/trayTemplate@2x.png?asset';

import { formatDuration } from '../shared/time';
import type { Tracker } from './tracker';

/**
 * The SprintSync "S" (generated from the brand mark by `npm run icons`).
 * macOS gets a black template image that the menu bar tints for light and
 * dark mode; Windows and Linux trays get the colour mark.
 */
function trayIcon(): Electron.NativeImage {
    const [standard, retina] = process.platform === 'darwin' ? [templateIcon, templateIcon2x] : [colourIcon, colourIcon2x];
    const image = nativeImage.createEmpty();
    image.addRepresentation({ scaleFactor: 1, buffer: readFileSync(standard) });
    image.addRepresentation({ scaleFactor: 2, buffer: readFileSync(retina) });
    image.setTemplateImage(process.platform === 'darwin');

    return image;
}

export interface TrayActions {
    show(): void;
    quit(): void;
}

/**
 * Menu-bar / tray item: the running time at a glance and the timer controls,
 * so the window can stay closed while tracking.
 */
export class TrackerTray {
    private readonly tray: Tray;

    constructor(
        private readonly tracker: Tracker,
        private readonly actions: TrayActions,
    ) {
        this.tray = new Tray(trayIcon());
        this.tray.setToolTip('SprintSync');
        this.tray.on('click', () => this.actions.show());
        this.update();
    }

    update(): void {
        const state = this.tracker.getState();
        const timer = state.timer;
        const elapsed = formatDuration(this.tracker.elapsed());
        const label = timer ? `${elapsed}${timer.state === 'paused' ? ' (paused)' : timer.state === 'lunch' ? ' (lunch)' : ''}` : '';

        if (process.platform === 'darwin') this.tray.setTitle(label);
        this.tray.setToolTip(timer ? `SprintSync — ${label} · ${timer.project.name}` : 'SprintSync');

        const items: Electron.MenuItemConstructorOptions[] = timer
            ? [
                  { label: `SprintSync — ${elapsed}`, enabled: false },
                  { label: timer.task ? `${timer.project.name} · ${timer.task.title}` : timer.project.name, enabled: false },
                  { type: 'separator' },
                  timer.state === 'lunch'
                      ? { label: 'End lunch', click: () => void this.tracker.endLunch() }
                      : timer.state === 'running'
                        ? { label: 'Pause', click: () => void this.tracker.pause() }
                        : { label: 'Resume', click: () => void this.tracker.resume() },
                  { label: 'Stop', click: () => void this.tracker.stop() },
              ]
            : [{ label: state.phase === 'ready' ? 'Start timer…' : 'Sign in…', click: () => this.actions.show() }];

        this.tray.setContextMenu(
            Menu.buildFromTemplate([
                ...items,
                { type: 'separator' },
                { label: 'Open SprintSync', click: () => this.actions.show() },
                { label: 'Quit', click: () => this.actions.quit() },
            ]),
        );
    }
}
