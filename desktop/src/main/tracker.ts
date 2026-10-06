import { randomUUID } from 'node:crypto';

import { elapsedSeconds, projectTimer } from '../shared/time';
import { ActivityMonitor } from './activity';
import type { AppState, Notice, PendingOperation, ServerTimer, TimerAction } from '../shared/types';
import { ApiError, NetworkError, type Overview, type Profile, type SprintSyncApi } from './api';
import type { LocalStore } from './local-store';
import type { SecretStore } from './secure-store';

export interface TrackerEvents {
    /** A short OS notification ("Timer started"). */
    notify(title: string, body: string): void;
}

export interface Device {
    name: string;
    platform: 'macos' | 'windows' | 'linux';
}

/** Ask SprintSync again this often while online (timer from another device, today's total). */
export const REFRESH_EVERY_MS = 60_000;

/** Remind once when a timer has been running this long. */
export const LONG_TIMER_SECONDS = 8 * 3600;

const START_ATTEMPTS = 3;

/**
 * The desktop app's state, owned by the main process. SprintSync is the
 * source of truth; this only shows its timer and sends changes.
 *
 * - Start needs a connection (a timer is never invented offline). A timeout is
 *   retried with the SAME operation key, so it starts at most once.
 * - Pause / Resume / Stop are written to a durable queue first, with when the
 *   person pressed the button, then sent in order. A change leaves the queue
 *   only when SprintSync confirmed it, or refused it for good (the timer
 *   changed on another device, or the change is too old). Offline, the window
 *   shows the timer with the queued changes applied.
 * - After syncing, SprintSync's timer replaces whatever this app believed.
 */
export class Tracker {
    private state: AppState;
    private serverTimer: ServerTimer | null;
    private listeners = new Set<(state: AppState) => void>();
    private flushing: Promise<void> | null = null;
    private lastRefreshAt = 0;
    private remindedSessions = new Set<string>();
    private activity = new ActivityMonitor();

    constructor(
        private readonly api: SprintSyncApi,
        private readonly store: LocalStore,
        private readonly secrets: SecretStore,
        private readonly device: Device,
        private readonly events: TrackerEvents,
        serverUrl: string,
        launchAtLoginSupported: boolean,
        appInfo: AppState['app'],
        private readonly now: () => number = Date.now,
    ) {
        const local = store.get();
        this.serverTimer = local.lastTimer;
        this.state = {
            phase: 'loading',
            user: null,
            workspaces: [],
            workspace: local.workspace,
            hasProfile: true,
            projects: [],
            selection: { ...local.selection },
            timer: projectTimer(local.lastTimer, local.queue),
            todaySeconds: 0,
            today: null,
            policy: local.lastPolicy,
            idlePeriods: [],
            online: true,
            pending: local.queue.length,
            busy: false,
            clockOffsetMs: 0,
            notice: null,
            signInError: null,
            preferences: { launchAtLogin: local.launchAtLogin, launchAtLoginSupported },
            serverUrl,
            app: appInfo,
        };
    }

    /* ---- Reading state ------------------------------------------------- */

    getState(): AppState {
        return this.state;
    }

    subscribe(listener: (state: AppState) => void): () => void {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    }

    /** Elapsed seconds on the shown timer right now (for the tray). */
    elapsed(): number {
        return elapsedSeconds(this.state.timer, this.now(), this.state.clockOffsetMs);
    }

    /* ---- Signing in and out --------------------------------------------- */

    async init(): Promise<void> {
        if (this.secrets.read() === null) {
            this.set({ phase: 'signed-out' });

            return;
        }

        try {
            this.applyProfile(await this.api.me());
            this.set({ phase: 'ready', online: true });
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                this.forgetSession('Please sign in again.');

                return;
            }

            /* Offline at launch: show the last known timer and keep trying. */
            this.set({ phase: 'ready', online: false });

            return;
        }

        await this.sync();
    }

    async signIn(email: string, password: string): Promise<void> {
        this.set({ busy: true, signInError: null });

        try {
            const result = await this.api.signIn(email, password, this.device.name, this.device.platform);
            this.secrets.write(result.token);
            this.applyProfile(result);
            this.set({ phase: 'ready', online: true, notice: this.secrets.isAvailable() ? null : { kind: 'warning', text: "This computer has no secure keychain, so you'll need to sign in each time you open SprintSync." } });
            await this.sync();
        } catch (error) {
            this.set({ signInError: error instanceof NetworkError ? "Can't reach SprintSync. Check your connection." : error instanceof ApiError ? error.message : 'Sign in failed.' });
        } finally {
            this.set({ busy: false });
        }
    }

    async signOut(): Promise<void> {
        this.set({ busy: true });
        await this.flush();

        let revoked = true;
        try {
            await this.api.signOut();
        } catch (error) {
            revoked = error instanceof ApiError && error.status === 401;
        }

        this.forgetSession(revoked ? null : "Signed out here, but SprintSync couldn't be reached to sign this device out. Remove it later from SprintSync.");
        this.set({ busy: false });
    }

    /* ---- Choosing ------------------------------------------------------- */

    async selectWorkspace(slug: string): Promise<void> {
        if (!this.state.workspaces.some((workspace) => workspace.slug === slug)) return;

        this.store.update({ workspace: slug, selection: { project: null, task: null } });
        this.set({ workspace: slug, selection: { project: null, task: null }, projects: [] });
        await this.refresh();
    }

    select(project: string | null, task: string | null): void {
        const option = this.state.projects.find((candidate) => candidate.public_id === project);
        const selection = { project: option ? project : null, task: option && option.tasks.some((t) => t.public_id === task) ? task : null };

        this.store.update({ selection });
        this.set({ selection });
    }

    setLaunchAtLogin(enabled: boolean): void {
        this.store.update({ launchAtLogin: enabled });
        this.set({ preferences: { ...this.state.preferences, launchAtLogin: enabled } });
    }

    dismissNotice(): void {
        this.set({ notice: null });
    }

    /* ---- Timer ---------------------------------------------------------- */

    async start(): Promise<void> {
        const { workspace, selection, timer } = this.state;

        if (!workspace || !selection.project || timer || this.state.busy) return;

        if (!this.state.online) {
            this.set({ notice: { kind: 'warning', text: "You're offline. Start the timer when you're back online." } });

            return;
        }

        this.set({ busy: true });
        const operationKey = randomUUID();

        try {
            for (let attempt = 1; ; attempt++) {
                try {
                    const { timer: started } = await this.api.start(workspace, { project: selection.project, task: selection.task, operation_key: operationKey });
                    this.acceptServerTimer(started);
                    this.events.notify('Timer started', started.task ? `${started.project.name} · ${started.task.title}` : started.project.name);
                    break;
                } catch (error) {
                    if (error instanceof NetworkError && attempt < START_ATTEMPTS) continue;
                    throw error;
                }
            }
        } catch (error) {
            await this.handleStartError(error);
        } finally {
            this.set({ busy: false });
        }
    }

    pause(): Promise<void> {
        return this.change('pause');
    }

    startLunch(): Promise<void> {
        return this.change('lunch_start');
    }

    endLunch(): Promise<void> {
        return this.change('lunch_end');
    }

    /**
     * Called every few seconds with the OS idle clock, only while the
     * workspace has idle detection on. When the computer has been unused for
     * the server's threshold (the last policy SprintSync sent, so it works
     * offline too), the timer is paused from the last activity: an idle pause
     * is queued like any other change and synced with its times.
     */
    async checkIdle(idleSeconds: number): Promise<void> {
        const { policy, timer, workspace } = this.state;

        if (this.state.phase !== 'ready' || !policy?.idle_enabled || !policy.idle_seconds || !timer || !workspace || timer.state !== 'running') return;
        if (idleSeconds < policy.idle_seconds) return;

        const now = this.now() + this.state.clockOffsetMs;
        const minutes = Math.round(policy.idle_seconds / 60);

        this.store.enqueue({
            id: randomUUID(),
            action: 'idle_pause',
            workspace,
            session: timer.public_id,
            occurredAt: new Date(now).toISOString(),
            idleSince: new Date(now - idleSeconds * 1000).toISOString(),
            createdAt: new Date(this.now()).toISOString(),
        });
        this.set({ notice: { kind: 'info', text: `Timer paused after ${minutes} minutes of inactivity.` } });
        this.events.notify('Timer paused', `Paused after ${minutes} minutes of inactivity. Open SprintSync to resume or review the idle time.`);

        await this.sync();
    }

    /** Activity integrity (only when the workspace turned it on): see ActivityMonitor. */
    sampleActivity(idleSeconds: number): void {
        const { policy, timer, workspace } = this.state;

        if (!policy?.integrity_enabled || timer?.state !== 'running' || !workspace || !this.state.online) {
            this.activity.reset();

            return;
        }

        const window = this.activity.sample(idleSeconds, this.now());

        if (window) {
            /* Best effort: a window lost while offline is simply skipped. */
            void this.api.activity(workspace, window, this.state.clockOffsetMs).catch(() => undefined);
        }
    }

    async keepIdle(idlePeriod: string, reason: string): Promise<void> {
        await this.decideIdle(() => this.api.keepIdle(this.state.workspace!, idlePeriod, reason));
    }

    async dropIdle(idlePeriod: string): Promise<void> {
        await this.decideIdle(() => this.api.dropIdle(this.state.workspace!, idlePeriod));
    }

    private async decideIdle(call: () => Promise<unknown>): Promise<void> {
        if (!this.state.workspace) return;

        if (!this.state.online) {
            this.set({ notice: { kind: 'warning', text: "You're offline. Review idle time when you're back online." } });

            return;
        }

        this.set({ busy: true });
        try {
            await call();
            this.set({ notice: null });
        } catch (error) {
            this.set({ notice: { kind: 'error', text: error instanceof ApiError ? error.message : "That couldn't be saved." } });
        } finally {
            this.set({ busy: false });
        }

        await this.refresh();
    }

    resume(): Promise<void> {
        return this.change('resume');
    }

    stop(): Promise<void> {
        return this.change('stop');
    }

    private async change(action: TimerAction): Promise<void> {
        const { timer, workspace } = this.state;

        if (!timer || !workspace) return;
        if ((action === 'pause' || action === 'lunch_start') && timer.state !== 'running') return;
        if (action === 'resume' && timer.state !== 'paused') return;
        if (action === 'lunch_end' && timer.state !== 'lunch') return;
        if (action === 'lunch_start' && !this.state.policy?.lunch_enabled) return;

        this.store.enqueue({
            id: randomUUID(),
            action,
            workspace,
            session: timer.public_id,
            occurredAt: new Date(this.now() + this.state.clockOffsetMs).toISOString(),
            createdAt: new Date(this.now()).toISOString(),
        });
        this.render();

        await this.sync();
    }

    /* ---- Syncing -------------------------------------------------------- */

    /** Called regularly: retry while offline, refresh now and then while online. */
    async tick(): Promise<void> {
        if (this.state.phase !== 'ready') return;

        if (!this.state.online || this.store.get().queue.length > 0 || this.now() - this.lastRefreshAt >= REFRESH_EVERY_MS) {
            await this.sync();
        }

        this.remindLongTimer();
    }

    /** Send queued changes, then take SprintSync's view of the timer. */
    async sync(): Promise<void> {
        await this.flush();
        await this.refresh();
    }

    /** Sends queued changes in order. One flush at a time. */
    flush(): Promise<void> {
        this.flushing ??= this.drain().finally(() => (this.flushing = null));

        return this.flushing;
    }

    private async drain(): Promise<void> {
        for (let operation = this.store.get().queue[0]; operation; operation = this.store.get().queue[0]) {
            try {
                const { timer } = await this.api.change(operation.workspace, operation.action, {
                    operation_key: operation.id,
                    session: operation.session,
                    occurred_at: operation.occurredAt,
                    ...(operation.idleSince ? { idle_since: operation.idleSince } : {}),
                });
                this.store.dequeue(operation.id);

                if (operation.workspace === this.state.workspace) {
                    this.serverTimer = timer.state === 'stopped' ? null : timer;
                    this.store.update({ lastTimer: this.serverTimer });
                }

                this.set({ online: true });
            } catch (error) {
                if (error instanceof NetworkError) {
                    this.set({ online: false });

                    return;
                }

                if (error instanceof ApiError && error.status === 401) {
                    this.forgetSession('Please sign in again. Changes made while offline could not be synced.');

                    return;
                }

                /* Refused for good: drop it (never invent time) and let SprintSync's timer show. */
                this.store.dequeue(operation.id);
                this.set({ online: true, notice: this.refusalNotice(error) });
            }
        }

        this.render();
    }

    async refresh(): Promise<void> {
        const workspace = this.state.workspace;

        if (this.state.phase !== 'ready' || !workspace) return;

        const shownBefore = this.state.timer;
        const sentAt = this.now();
        let overview: Overview;

        try {
            overview = await this.api.overview(workspace);
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                this.forgetSession('Please sign in again.');
            } else if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
                this.set({ online: true, notice: { kind: 'error', text: "You don't have access to that workspace any more." } });
            } else {
                this.set({ online: false });
            }

            return;
        }

        const firstLook = this.lastRefreshAt === 0 && shownBefore === null;
        this.lastRefreshAt = this.now();
        const offset = Date.parse(overview.server_time) - Math.round((sentAt + this.now()) / 2);

        /* Queued changes still waiting? SprintSync's timer is shown with them applied. */
        this.serverTimer = overview.timer;
        this.store.update({ lastTimer: overview.timer, lastPolicy: overview.policy ?? null });
        this.set({
            online: true,
            hasProfile: overview.has_profile,
            projects: overview.projects,
            todaySeconds: overview.today_seconds,
            today: overview.today_breakdown ?? null,
            policy: overview.policy ?? null,
            idlePeriods: overview.idle_periods ?? [],
            clockOffsetMs: Number.isFinite(offset) ? offset : 0,
        });
        this.select(this.state.selection.project ?? overview.projects[0]?.public_id ?? null, this.state.selection.task);

        if (!firstLook && this.store.get().queue.length === 0 && differs(shownBefore, this.state.timer) && this.state.notice === null) {
            this.set({ notice: { kind: 'info', text: 'Timer was updated from SprintSync.' } });
        }
    }

    /* ---- Helpers -------------------------------------------------------- */

    private acceptServerTimer(timer: ServerTimer | null): void {
        this.serverTimer = timer?.state === 'stopped' ? null : timer;
        this.store.update({ lastTimer: this.serverTimer });
        this.render();
    }

    private async handleStartError(error: unknown): Promise<void> {
        if (error instanceof NetworkError) {
            /* The start may have reached SprintSync; it will show on the next refresh. */
            this.set({ online: false, notice: { kind: 'warning', text: "Couldn't reach SprintSync to start the timer. We'll check again when you're back online." } });

            return;
        }

        if (error instanceof ApiError && error.code === 'TIME.TIMER.ALREADY_RUNNING') {
            this.set({ notice: { kind: 'info', text: 'A timer is already running in SprintSync. We\'ve brought it up here.' } });
            await this.refresh();

            return;
        }

        if (error instanceof ApiError && error.status === 401) {
            this.forgetSession('Please sign in again.');

            return;
        }

        this.set({ notice: { kind: 'error', text: error instanceof ApiError ? error.message : "The timer couldn't be started." } });
    }

    private refusalNotice(error: unknown): Notice {
        if (error instanceof ApiError && (error.code === 'TIME.TIMER.CHANGED' || error.code === 'TIME.TIMER.NOT_RUNNING')) {
            return { kind: 'info', text: "Your timer changed on another device. We've refreshed it." };
        }

        if (error instanceof ApiError && error.status === 422) {
            return { kind: 'warning', text: `A change made offline couldn't be synced: ${error.message}` };
        }

        return { kind: 'error', text: error instanceof ApiError ? error.message : "A timer change couldn't be synced." };
    }

    private applyProfile(profile: Profile): void {
        const stored = this.state.workspace;
        const usable = profile.workspaces.find((workspace) => workspace.slug === stored) ?? profile.workspaces.find((workspace) => workspace.has_profile) ?? profile.workspaces[0] ?? null;

        this.store.update({ workspace: usable?.slug ?? null });
        this.set({ user: profile.user, workspaces: profile.workspaces, workspace: usable?.slug ?? null, signInError: null });
    }

    /** Signed out or the token stopped working: nothing of the session stays on this computer. */
    private forgetSession(message: string | null): void {
        this.secrets.clear();
        this.serverTimer = null;
        this.store.update({ lastTimer: null, lastPolicy: null, queue: [] });
        this.activity.reset();
        this.set({
            phase: 'signed-out',
            user: null,
            workspaces: [],
            projects: [],
            todaySeconds: 0,
            today: null,
            policy: null,
            idlePeriods: [],
            notice: null,
            signInError: message,
        });
    }

    private remindLongTimer(): void {
        const timer = this.state.timer;

        if (!timer || timer.state !== 'running' || this.remindedSessions.has(timer.public_id)) return;

        if (this.elapsed() >= LONG_TIMER_SECONDS) {
            this.remindedSessions.add(timer.public_id);
            this.events.notify('Timer still running', `Your timer has been running for 8 hours (${timer.project.name}).`);
        }
    }

    /** Recompute the shown timer from SprintSync's plus the queue. */
    private render(): void {
        const queue = this.store.get().queue;
        this.set({ timer: projectTimer(this.serverTimer, queue.filter((operation) => operation.workspace === this.state.workspace)), pending: queue.length });
    }

    private set(change: Partial<AppState>): void {
        this.state = { ...this.state, ...change };

        if (!('timer' in change)) {
            const queue = this.store.get().queue;
            this.state.timer = projectTimer(this.serverTimer, queue.filter((operation) => operation.workspace === this.state.workspace));
            this.state.pending = queue.length;
        }

        for (const listener of this.listeners) listener(this.state);
    }
}

function differs(a: ServerTimer | null, b: ServerTimer | null): boolean {
    return (a?.public_id ?? null) !== (b?.public_id ?? null) || (a?.state ?? null) !== (b?.state ?? null);
}

export type { PendingOperation };
