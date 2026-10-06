# SprintSync Desktop

A small time tracker for SprintSync. It signs in, shows your timer and sends
Start / Pause / Resume / Stop to SprintSync, which stays the source of truth.
It does not take screenshots, log keys, watch apps or track the mouse.

## Run it (development / QA)

```bash
cd desktop
npm install
npm run dev
```

It connects to `http://127.0.0.1:8124` (the local QA server) by default. To
point a build somewhere else, set `MAIN_VITE_SPRINTSYNC_URL` (see
`.env.example`). Packaged builds only accept `https://`.

If Electron starts as plain Node ("does not provide an export named
'BrowserWindow'"), your shell has `ELECTRON_RUN_AS_NODE` set (some editor
terminals do). Run `unset ELECTRON_RUN_AS_NODE` first.

| Command | What it does |
| --- | --- |
| `npm run dev` | Run the app with live reload |
| `npm test` | Unit tests (offline queue, sync, clock, storage, API) |
| `npm run typecheck` / `npm run lint` | Types and lint |
| `npm run build` | Production bundle in `out/` |
| `npm run package:dir` | Unsigned app folder in `dist/` for local testing |
| `npm run package:mac` / `package:win` | Unsigned UAT installers in `dist/` (dmg + zip / NSIS + zip) |

## How it works

- **Main process** (`src/main`) talks to SprintSync, keeps the sign-in token
  and the offline queue. **Preload** exposes a fixed list of actions.
  **Renderer** (`src/renderer`, Vue) only displays state.
- **Sign-in** gets a per-device token (`/api/desktop/sign-in`). It is stored
  encrypted by the OS through Electron `safeStorage` (macOS Keychain, Windows
  DPAPI, Linux Secret Service / KWallet). On Linux without a keyring the token
  is kept in memory only. Signing out revokes it on the server.
- **Offline**: Pause / Resume / Stop are saved to a durable queue with the time
  they were pressed, then sent in order with the same operation id until
  SprintSync confirms them. Start needs a connection.
- **Local files** (in the app's user-data folder): `sprintsync-state.json`
  (preferences, selection, last timer seen, queue) and
  `sprintsync-session.bin` (the encrypted token). Nothing else.

## UAT builds and downloads

The version lives in one place, `package.json`; the app, the installer names
and `desktop:publish-release` all read it.

Build (on a Mac, for this Mac's architecture):

```bash
cd desktop
MAIN_VITE_SPRINTSYNC_URL=https://<uat-domain> MAIN_VITE_SPRINTSYNC_CHANNEL=uat \
  npm run build && npx electron-builder --mac --arm64
# → dist/SprintSync-0.1.0-macos-arm64.dmg (and .zip)
```

For a QA server on this computer over http, add `MAIN_VITE_SPRINTSYNC_LOCAL_UAT=1`
(allows http to 127.0.0.1 / localhost only; never for a build that leaves your
machine). Any other address still needs HTTPS.

Publish it so signed-in people can download it from the Time page (from the
repository root):

```bash
php artisan desktop:publish-release desktop/dist/SprintSync-0.1.0-macos-arm64.dmg \
    --platform=macos --arch=arm64 --channel=uat
```

UAT builds are for signed-in people only; `stable` builds appear on the
public landing page. Downloads come from SprintSync's private storage with a
SHA-256 checksum (shown on the Time page, sent as `X-Checksum-SHA256`).

Icons are generated from the SprintSync mark (`public/favicon.svg`) with
`npm run icons` (writes `build/` and `resources/tray/`; commit the result).

### Installing a UAT build

UAT builds are **unsigned test builds**, so the operating system warns once.
Don't turn off any security setting; approve SprintSync only.

**macOS**

1. Download SprintSync from the Time page in SprintSync.
2. Open the `.dmg` and drag SprintSync to Applications.
3. First launch: right-click SprintSync in Applications → Open → Open. On
   macOS 15 and later, if it only offers "Done" / "Move to Trash": choose Done,
   then System Settings → Privacy & Security → "SprintSync was blocked…" →
   Open Anyway.
4. If macOS asks for SprintSync to use "SprintSync Safe Storage" in your
   keychain, choose Always Allow (that is where the sign-in is kept). A new
   UAT build may ask again.
5. Sign in with your SprintSync email and password.

**Windows**

1. Run `SprintSync-<version>-windows-x64.exe`.
2. SmartScreen shows "Windows protected your PC": More info → Run anyway.
3. Sign in.

## Before a production release

- **macOS**: sign with an Apple Developer ID Application certificate
  (`mac.identity`), set `hardenedRuntime: true` (with Electron's entitlements)
  and notarize. UAT builds carry only an ad-hoc signature, which Apple silicon
  needs to run the app but which is not a Developer ID.
- **Windows**: sign with a code-signing certificate (EV avoids SmartScreen
  warnings) and set `win.publisherName`. Build the Windows installer on
  Windows (or a CI Windows runner); cross-building from a Mac needs Wine.
- **Updates**: add an update channel (for example `electron-updater` with a
  signed feed or GitHub releases). Updates must be signed; never load code
  over plain HTTP.
- Build with the production `MAIN_VITE_SPRINTSYNC_URL` (https) and
  `MAIN_VITE_SPRINTSYNC_CHANNEL=stable`; publish with `--channel=stable --signed`.
- The packaged binary already has Electron fuses set (no run-as-Node, no
  `NODE_OPTIONS` / `--inspect`, app archive only, archive integrity checked).
