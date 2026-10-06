/**
 * Regenerates the app and tray icons. Sources:
 *
 *   build/icon.svg        the app icon (master, vector)
 *   build/icon-small.svg  the same icon simplified for 16–32 px
 *   public/favicon.svg    the S, reused for the monochrome menu-bar icon
 *
 * Runs inside Electron (already a dependency) so no image tools are needed:
 *
 *   npm run icons
 *
 * Writes build/icon.icns (macOS, tile inset on Apple's 1024 grid),
 * build/icon.ico (Windows, 16–256), build/icons/NxN.png (Linux),
 * build/icon.png and the tray images in resources/tray. Commit the results.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { app, BrowserWindow } from 'electron';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const master = readFileSync(join(root, 'build/icon.svg'), 'utf8');
const small = readFileSync(join(root, 'build/icon-small.svg'), 'utf8');
const favicon = readFileSync(join(root, '../public/favicon.svg'), 'utf8');

/* At 32 px and below the layered detail turns to mush; use the flat version. */
const SMALL_MAX = 32;
const inner = (svg) => svg.slice(svg.indexOf('>') + 1, svg.lastIndexOf('</svg>'));
const viewBox = (svg) => svg.match(/viewBox="([^"]+)"/)[1];
const place = (svg, size, scale) => {
    const side = size * scale;
    const offset = (size - side) / 2;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"><svg x="${offset}" y="${offset}" width="${side}" height="${side}" viewBox="${viewBox(svg)}">${inner(svg)}</svg></svg>`;
};
const sourceFor = (size) => (size <= SMALL_MAX ? small : master);
const sPath = favicon.match(/<path d="([^"]+)"/)[1];

const VARIANTS = {
    /** macOS: the tile is 824/1024 of the canvas, like every other Dock icon. */
    mac: (size) => place(sourceFor(size), size, 824 / 1024),
    /** Windows and Linux: nearly edge to edge. */
    full: (size) => place(sourceFor(size), size, 0.94),
    /** Menu bar: the S alone, black on transparent; macOS tints it. */
    template: (size) =>
        `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="17 14.5 66 66"><path d="${sPath}" fill="none" stroke="#000" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
};

app.commandLine.appendSwitch('force-device-scale-factor', '1');
app.dock?.hide();

let window;

async function render(variant, size) {
    window ??= new BrowserWindow({ width: 1024, height: 1024, show: false, transparent: true, frame: false, webPreferences: { offscreen: true } });
    const html = `<html><body style="margin:0;background:transparent">${VARIANTS[variant](size)}</body></html>`;
    await window.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
    await new Promise((resolve) => setTimeout(resolve, 150));
    const image = await window.webContents.capturePage({ x: 0, y: 0, width: size, height: size });
    if (image.getSize().width !== size) throw new Error(`Rendered ${image.getSize().width}px for ${size}px`);
    return image.toPNG();
}

/** ICO with PNG entries (Windows Vista and later). */
function ico(pngs) {
    const header = Buffer.alloc(6 + 16 * pngs.length);
    header.writeUInt16LE(1, 2);
    header.writeUInt16LE(pngs.length, 4);
    let offset = header.length;
    pngs.forEach(({ size, png }, index) => {
        const entry = 6 + 16 * index;
        header.writeUInt8(size >= 256 ? 0 : size, entry);
        header.writeUInt8(size >= 256 ? 0 : size, entry + 1);
        header.writeUInt16LE(1, entry + 4);
        header.writeUInt16LE(32, entry + 6);
        header.writeUInt32LE(png.length, entry + 8);
        header.writeUInt32LE(offset, entry + 12);
        offset += png.length;
    });
    return Buffer.concat([header, ...pngs.map(({ png }) => png)]);
}

app.whenReady().then(async () => {
  try {
    const build = join(root, 'build');
    const iconset = join(build, 'icon.iconset');
    rmSync(iconset, { recursive: true, force: true });
    mkdirSync(iconset, { recursive: true });
    mkdirSync(join(build, 'icons'), { recursive: true });
    mkdirSync(join(root, 'resources/tray'), { recursive: true });

    for (const size of [16, 32, 128, 256, 512]) {
        writeFileSync(join(iconset, `icon_${size}x${size}.png`), await render('mac', size));
        writeFileSync(join(iconset, `icon_${size}x${size}@2x.png`), await render('mac', size * 2));
    }
    execFileSync('iconutil', ['-c', 'icns', iconset, '-o', join(build, 'icon.icns')]);
    rmSync(iconset, { recursive: true });

    const windows = [];
    for (const size of [16, 24, 32, 48, 64, 128, 256]) windows.push({ size, png: await render('full', size) });
    writeFileSync(join(build, 'icon.ico'), ico(windows));

    for (const size of [16, 32, 48, 64, 128, 256, 512, 1024]) writeFileSync(join(build, 'icons', `${size}x${size}.png`), await render('full', size));
    writeFileSync(join(build, 'icon.png'), await render('full', 1024));

    writeFileSync(join(root, 'resources/tray/trayTemplate.png'), await render('template', 16));
    writeFileSync(join(root, 'resources/tray/trayTemplate@2x.png'), await render('template', 32));
    writeFileSync(join(root, 'resources/tray/tray.png'), await render('full', 16));
    writeFileSync(join(root, 'resources/tray/tray@2x.png'), await render('full', 32));

    console.log('Icons written to build/ and resources/tray/.');
  } catch (error) {
    console.error(error);
    process.exitCode = 1;
  }
  app.quit();
});
