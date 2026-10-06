import { describe, expect, it } from 'vitest';

import { ApiError, NetworkError, type Overview, type Profile, type SprintSyncApi } from '../src/main/api';
import { LocalStore } from '../src/main/local-store';
import type { SecretStore } from '../src/main/secure-store';
import { Tracker } from '../src/main/tracker';
import type { ServerTimer, TimePolicy, TimerAction } from '../src/shared/types';
import { serverTimer, tempFolder } from './fixtures';

const PROFILE: Profile = { user: { name: 'Usman', email: 'member@example.com' }, workspaces: [{ slug: 'sprintsync', name: 'SprintSync', has_profile: true }] };

/**
 * A small stand-in for SprintSync's timer API with its real rules: one timer,
 * operation keys applied once, late changes counted from occurred_at.
 */
class FakeSprintSync {
    online = true;
    timer: ServerTimer | null = null;
    entries: { session: string; seconds: number; endedAt: string }[] = [];
    seenKeys = new Map<string, ServerTimer>();
    calls: { action: string; key?: string; occurredAt?: string; idleSince?: string }[] = [];
    now = '2026-10-12T09:00:00.000Z';
    signedOut = false;
    unauthorized = false;
    policy: TimePolicy = { version: null, idle_enabled: false, idle_seconds: null, weekly_target_seconds: null, lunch_enabled: false, lunch_allowance_seconds: null, overtime_mode: 'track_only', integrity_enabled: false };
    activityWindows: unknown[] = [];
    lunches: { from: string; to: string }[] = [];

    private gate(): void {
        if (!this.online) throw new NetworkError();
        if (this.unauthorized) throw new ApiError(401, null, 'Please sign in again.');
    }

    api(): SprintSyncApi {
        const api = {
            signIn: async () => {
                this.gate();

                return { token: 'ssd_secret-token', ...PROFILE };
            },
            signOut: async () => {
                this.gate();
                this.signedOut = true;
            },
            me: async () => {
                this.gate();

                return PROFILE;
            },
            overview: async (): Promise<Overview> => {
                this.gate();

                return {
                    has_profile: true,
                    projects: [{ public_id: 'p1', name: 'RocketFlood CSR', tasks: [{ public_id: 't1', title: 'Flood Quote Workflow' }] }],
                    timer: this.timer,
                    today_seconds: this.entries.reduce((sum, entry) => sum + entry.seconds, 0),
                    today: '2026-10-12',
                    timezone: 'UTC',
                    server_time: this.now,
                    policy: this.policy,
                    today_breakdown: null,
                    idle_periods: [],
                };
            },
            start: async (_workspace: string, body: { project: string; task: string | null; operation_key: string }) => {
                this.calls.push({ action: 'start', key: body.operation_key });
                this.gate();
                const seen = this.seenKeys.get(body.operation_key);
                if (seen) return { timer: seen };
                if (this.timer) throw new ApiError(409, 'TIME.TIMER.ALREADY_RUNNING', 'You already have a timer running.');
                this.timer = serverTimer({ public_id: `session-${this.seenKeys.size + 1}`, started_at: this.now, running_since: this.now });
                this.seenKeys.set(body.operation_key, this.timer);

                return { timer: this.timer };
            },
            activity: async (_workspace: string, window: unknown) => {
                this.gate();
                this.activityWindows.push(window);
            },
            change: async (_workspace: string, action: TimerAction, body: { operation_key: string; session: string; occurred_at: string; idle_since?: string }) => {
                this.calls.push({ action, key: body.operation_key, occurredAt: body.occurred_at, idleSince: body.idle_since });
                this.gate();
                const seen = this.seenKeys.get(body.operation_key);
                if (seen) return { timer: seen };
                if (!this.timer || this.timer.public_id !== body.session) throw new ApiError(409, 'TIME.TIMER.CHANGED', "Your timer changed on another device. We've refreshed it.");

                const at = Date.parse(body.occurred_at);
                const running = this.timer.state === 'running' && this.timer.running_since ? Math.floor((at - Date.parse(this.timer.running_since)) / 1000) : 0;
                let result: ServerTimer;

                if (action === 'pause') {
                    result = this.timer = { ...this.timer, state: 'paused', running_since: null, accumulated_seconds: this.timer.accumulated_seconds + running };
                } else if (action === 'idle_pause') {
                    const since = Date.parse(body.idle_since!);
                    const worked = Math.floor((since - Date.parse(this.timer.running_since!)) / 1000);
                    result = this.timer = { ...this.timer, state: 'paused', pause_reason: 'idle', running_since: null, accumulated_seconds: this.timer.accumulated_seconds + worked };
                } else if (action === 'lunch_start') {
                    result = this.timer = { ...this.timer, state: 'lunch', running_since: null, lunch_started_at: body.occurred_at, accumulated_seconds: this.timer.accumulated_seconds + running };
                } else if (action === 'lunch_end') {
                    this.lunches.push({ from: this.timer.lunch_started_at!, to: body.occurred_at });
                    result = this.timer = { ...this.timer, state: 'running', lunch_started_at: null, running_since: body.occurred_at };
                } else if (action === 'resume') {
                    result = this.timer = { ...this.timer, state: 'running', running_since: body.occurred_at };
                } else {
                    result = { ...this.timer, state: 'stopped', accumulated_seconds: this.timer.accumulated_seconds + running, entry_public_id: `entry-${this.entries.length + 1}` };
                    this.entries.push({ session: this.timer.public_id, seconds: result.accumulated_seconds, endedAt: body.occurred_at });
                    this.timer = null;
                }

                this.seenKeys.set(body.operation_key, result);

                return { timer: result };
            },
        };

        return api as unknown as SprintSyncApi;
    }
}

class MemorySecrets implements SecretStore {
    constructor(public value: string | null = null) {}
    isAvailable() {
        return true;
    }
    read() {
        return this.value;
    }
    write(secret: string) {
        this.value = secret;
    }
    clear() {
        this.value = null;
    }
}

function setUp(options: { signedIn?: boolean; folder?: string; server?: FakeSprintSync } = {}) {
    const server = options.server ?? new FakeSprintSync();
    const folder = options.folder ?? tempFolder();
    const secrets = new MemorySecrets(options.signedIn === false ? null : 'ssd_secret-token');
    const notifications: string[] = [];
    let clock = Date.parse(server.now);
    const tracker = new Tracker(
        server.api(),
        LocalStore.inFolder(folder),
        secrets,
        { name: 'Test Mac', platform: 'macos' },
        { notify: (title) => notifications.push(title) },
        'http://127.0.0.1:8124',
        true,
        { version: '0.1.0', channel: 'uat' },
        () => clock,
    );
    const at = (time: string) => {
        server.now = `2026-10-12T${time}.000Z`;
        clock = Date.parse(server.now);
    };

    return { server, tracker, secrets, folder, notifications, at };
}

async function readyWithProject(setup: ReturnType<typeof setUp>) {
    await setup.tracker.init();
    setup.tracker.select('p1', 't1');
}

describe('signing in', () => {
    it('keeps the token out of the window state', async () => {
        const setup = setUp({ signedIn: false });
        await setup.tracker.init();
        expect(setup.tracker.getState().phase).toBe('signed-out');

        await setup.tracker.signIn('member@example.com', 'password');

        expect(setup.tracker.getState().phase).toBe('ready');
        expect(setup.secrets.value).toBe('ssd_secret-token');
        expect(JSON.stringify(setup.tracker.getState())).not.toContain('ssd_secret-token');
        expect(JSON.stringify(setup.tracker.getState())).not.toContain('password');
    });

    it('signs out: revokes on the server and forgets everything locally', async () => {
        const setup = setUp();
        await readyWithProject(setup);

        await setup.tracker.signOut();

        expect(setup.server.signedOut).toBe(true);
        expect(setup.secrets.value).toBeNull();
        expect(setup.tracker.getState().phase).toBe('signed-out');
    });

    it('a revoked token signs the app out', async () => {
        const setup = setUp();
        setup.server.unauthorized = true;

        await setup.tracker.init();

        expect(setup.tracker.getState().phase).toBe('signed-out');
        expect(setup.secrets.value).toBeNull();
    });
});

describe('the timer', () => {
    it('starts, pauses, resumes and stops through SprintSync', async () => {
        const setup = setUp();
        await readyWithProject(setup);

        await setup.tracker.start();
        expect(setup.tracker.getState().timer?.state).toBe('running');
        expect(setup.notifications).toEqual(['Timer started']);

        setup.at('10:00:00');
        await setup.tracker.pause();
        setup.at('10:30:00');
        await setup.tracker.resume();
        setup.at('11:00:00');
        await setup.tracker.stop();

        expect(setup.tracker.getState().timer).toBeNull();
        expect(setup.server.entries).toEqual([{ session: 'session-1', seconds: 5400, endedAt: '2026-10-12T11:00:00.000Z' }]);
        expect(setup.tracker.getState().todaySeconds).toBe(5400);
        expect(setup.tracker.getState().pending).toBe(0);
    });

    it('retries a timed-out start with the same operation key', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        const api = setup.server.api();
        const start = api.start.bind(api);
        let failures = 1;
        /* The first request reaches SprintSync but the answer is lost. */
        (setup.tracker as unknown as { api: SprintSyncApi }).api.start = async (...args) => {
            const result = await start(...args);
            if (failures-- > 0) throw new NetworkError();

            return result;
        };

        await setup.tracker.start();

        const keys = setup.server.calls.filter((call) => call.action === 'start').map((call) => call.key);
        expect(keys).toHaveLength(2);
        expect(new Set(keys).size).toBe(1);
        expect(setup.tracker.getState().timer?.public_id).toBe('session-1');
    });

    it('never starts a second timer: it shows the one already running', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        setup.server.timer = serverTimer({ public_id: 'from-other-device' });

        await setup.tracker.start();

        expect(setup.tracker.getState().timer?.public_id).toBe('from-other-device');
    });

    it('does not start while offline', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        setup.server.online = false;
        await setup.tracker.refresh();

        await setup.tracker.start();

        expect(setup.server.timer).toBeNull();
        expect(setup.tracker.getState().notice?.text).toContain('offline');
    });
});

describe('offline', () => {
    it('a Stop pressed offline syncs once, at the time it was pressed', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        setup.at('13:00:00');
        setup.server.online = false;
        await setup.tracker.tick();
        expect(setup.tracker.getState().online).toBe(false);
        expect(setup.tracker.getState().timer?.state).toBe('running');

        setup.at('14:00:00');
        await setup.tracker.stop();
        expect(setup.tracker.getState().timer).toBeNull();
        expect(setup.tracker.getState().pending).toBe(1);

        setup.at('14:30:00');
        await setup.tracker.tick();
        await setup.tracker.tick();

        setup.at('15:00:00');
        setup.server.online = true;
        await setup.tracker.tick();
        await setup.tracker.tick();

        expect(setup.server.entries).toEqual([{ session: 'session-1', seconds: 18000, endedAt: '2026-10-12T14:00:00.000Z' }]);
        const stops = setup.server.calls.filter((call) => call.action === 'stop');
        expect(new Set(stops.map((call) => call.key)).size).toBe(1);
        expect(stops.every((call) => call.occurredAt === '2026-10-12T14:00:00.000Z')).toBe(true);
        expect(setup.tracker.getState().pending).toBe(0);
        expect(setup.tracker.getState().online).toBe(true);
    });

    it('if the answer is lost, the same change is resent and applied once', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();
        const api = setup.server.api();
        const change = api.change.bind(api);
        let lose = 1;
        (setup.tracker as unknown as { api: SprintSyncApi }).api.change = async (...args) => {
            const result = await change(...args);
            if (lose-- > 0) throw new NetworkError();

            return result;
        };

        setup.at('10:00:00');
        await setup.tracker.stop();
        expect(setup.tracker.getState().pending).toBe(1);
        await setup.tracker.tick();

        expect(setup.server.entries).toHaveLength(1);
        expect(setup.tracker.getState().pending).toBe(0);
    });

    it('queued pause then stop replay in order', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();
        setup.server.online = false;

        setup.at('10:00:00');
        await setup.tracker.pause();
        expect(setup.tracker.getState().timer?.state).toBe('paused');
        setup.at('10:30:00');
        await setup.tracker.stop();

        setup.server.online = true;
        await setup.tracker.tick();

        const sent = setup.server.calls.filter((call) => call.action !== 'start');
        expect(sent.map((call) => call.action).filter((action, index, all) => all.indexOf(action) === index)).toEqual(['pause', 'stop']);
        expect(setup.server.entries).toEqual([{ session: 'session-1', seconds: 3600, endedAt: '2026-10-12T10:30:00.000Z' }]);
    });

    it('the queue survives quitting the app', async () => {
        const folder = tempFolder();
        const server = new FakeSprintSync();
        const first = setUp({ folder, server });
        await readyWithProject(first);
        await first.tracker.start();
        server.online = false;
        first.at('12:00:00');
        await first.tracker.stop();

        /* The app is quit and opened again later, back online. */
        server.online = true;
        const second = setUp({ folder, server });
        await second.tracker.init();

        expect(server.entries).toEqual([{ session: 'session-1', seconds: 10800, endedAt: '2026-10-12T12:00:00.000Z' }]);
        expect(second.tracker.getState().pending).toBe(0);
    });

    it('shows the last known timer when opened offline', async () => {
        const folder = tempFolder();
        const server = new FakeSprintSync();
        const first = setUp({ folder, server });
        await readyWithProject(first);
        await first.tracker.start();

        server.online = false;
        const second = setUp({ folder, server });
        await second.tracker.init();

        expect(second.tracker.getState().online).toBe(false);
        expect(second.tracker.getState().timer?.public_id).toBe('session-1');
    });
});

describe('SprintSync wins', () => {
    it('a change for a timer that another device already changed is dropped, not invented', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();
        setup.server.online = false;
        setup.at('10:00:00');
        await setup.tracker.stop();

        /* Meanwhile, on the website: stopped and a new timer started. */
        setup.server.entries.push({ session: 'session-1', seconds: 1800, endedAt: '2026-10-12T09:30:00.000Z' });
        setup.server.timer = serverTimer({ public_id: 'session-web' });
        setup.server.online = true;
        await setup.tracker.tick();

        expect(setup.server.entries).toHaveLength(1);
        expect(setup.tracker.getState().pending).toBe(0);
        expect(setup.tracker.getState().timer?.public_id).toBe('session-web');
        expect(setup.tracker.getState().notice?.text).toBe("Your timer changed on another device. We've refreshed it.");
    });

    it('a timer stopped elsewhere disappears here with a short message', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        setup.server.timer = null;
        setup.at('09:02:00');
        await setup.tracker.sync();

        expect(setup.tracker.getState().timer).toBeNull();
        expect(setup.tracker.getState().notice?.text).toBe('Timer was updated from SprintSync.');
    });

    it('a timer started on another device shows up here', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.sync();

        setup.server.timer = serverTimer({ public_id: 'from-windows' });
        await setup.tracker.sync();

        expect(setup.tracker.getState().timer?.public_id).toBe('from-windows');
    });

    it('a revoked token while syncing signs out and clears the queue', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();
        setup.server.online = false;
        await setup.tracker.stop();

        setup.server.online = true;
        setup.server.unauthorized = true;
        await setup.tracker.tick();

        expect(setup.tracker.getState().phase).toBe('signed-out');
        expect(LocalStore.inFolder(setup.folder).get().queue).toEqual([]);
    });
});

describe('reminders', () => {
    it('reminds once after 8 hours of running', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        setup.at('16:59:00');
        await setup.tracker.tick();
        setup.at('17:00:00');
        await setup.tracker.tick();
        setup.at('18:00:00');
        await setup.tracker.tick();

        expect(setup.notifications).toEqual(['Timer started', 'Timer still running']);
    });
});

describe('paid lunch', () => {
    it('starts and ends lunch through SprintSync, and offline too', async () => {
        const setup = setUp();
        setup.server.policy = { ...setup.server.policy, lunch_enabled: true, lunch_allowance_seconds: 3600 };
        await readyWithProject(setup);
        await setup.tracker.start();

        setup.at('13:00:00');
        await setup.tracker.startLunch();
        expect(setup.tracker.getState().timer?.state).toBe('lunch');

        setup.server.online = false;
        setup.at('14:00:00');
        await setup.tracker.endLunch();
        expect(setup.tracker.getState().timer?.state).toBe('running');
        expect(setup.tracker.getState().pending).toBe(1);

        setup.server.online = true;
        setup.at('14:30:00');
        await setup.tracker.tick();
        await setup.tracker.tick();

        expect(setup.server.lunches).toEqual([{ from: '2026-10-12T13:00:00.000Z', to: '2026-10-12T14:00:00.000Z' }]);
        expect(new Set(setup.server.calls.filter((call) => call.action === 'lunch_end').map((call) => call.key)).size).toBe(1);
    });

    it('does nothing when the workspace has no paid lunch', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        await setup.tracker.startLunch();

        expect(setup.tracker.getState().timer?.state).toBe('running');
        expect(setup.server.calls.some((call) => call.action === 'lunch_start')).toBe(false);
    });
});

describe('idle detection', () => {
    it('is off unless the workspace turned it on', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        await setup.tracker.checkIdle(10_000);

        expect(setup.tracker.getState().timer?.state).toBe('running');
    });

    it('pauses from the last activity once the server threshold is reached', async () => {
        const setup = setUp();
        setup.server.policy = { ...setup.server.policy, idle_enabled: true, idle_seconds: 300 };
        await readyWithProject(setup);
        await setup.tracker.start();

        setup.at('10:04:00');
        await setup.tracker.checkIdle(299);
        expect(setup.tracker.getState().timer?.state).toBe('running');

        setup.at('10:05:00');
        await setup.tracker.checkIdle(300);

        const idle = setup.server.calls.find((call) => call.action === 'idle_pause');
        expect(idle?.idleSince).toBe('2026-10-12T10:00:00.000Z');
        expect(idle?.occurredAt).toBe('2026-10-12T10:05:00.000Z');
        expect(setup.tracker.getState().timer).toMatchObject({ state: 'paused', pause_reason: 'idle', accumulated_seconds: 3600 });
        expect(setup.notifications).toContain('Timer paused');

        /* Still idle: nothing more is sent. */
        await setup.tracker.checkIdle(400);
        expect(setup.server.calls.filter((call) => call.action === 'idle_pause')).toHaveLength(1);
    });

    it('works offline with the last policy and syncs the pause once', async () => {
        const folder = tempFolder();
        const server = new FakeSprintSync();
        server.policy = { ...server.policy, idle_enabled: true, idle_seconds: 120 };
        const first = setUp({ folder, server });
        await readyWithProject(first);
        await first.tracker.start();

        /* The app restarts offline: the policy comes from local state. */
        server.online = false;
        const second = setUp({ folder, server });
        await second.tracker.init();
        second.at('10:02:00');
        await second.tracker.checkIdle(125);

        expect(second.tracker.getState().timer?.state).toBe('paused');
        expect(second.tracker.getState().pending).toBe(1);

        server.online = true;
        await second.tracker.tick();
        await second.tracker.tick();

        const sent = server.calls.filter((call) => call.action === 'idle_pause');
        expect(new Set(sent.map((call) => call.key)).size).toBe(1);
        expect(sent[0]?.idleSince).toBe('2026-10-12T09:59:55.000Z');
        expect(server.timer?.accumulated_seconds).toBe(3595);
    });
});

describe('activity integrity sampling', () => {
    it('summarises only when on and while running, as counts and averages', async () => {
        const setup = setUp();
        setup.server.policy = { ...setup.server.policy, integrity_enabled: true };
        await readyWithProject(setup);
        await setup.tracker.start();

        /* A keep-awake tool: the idle clock resets every 30 seconds for 10 minutes. */
        for (let second = 0; second <= 600; second++) {
            const clock = `${String(9 + Math.floor((second) / 3600)).padStart(2, '0')}:${String(Math.floor((second % 3600) / 60)).padStart(2, '0')}:${String(second % 60).padStart(2, '0')}`;
            setup.at(clock);
            setup.tracker.sampleActivity(second % 30);
        }

        expect(setup.server.activityWindows).toHaveLength(1);
        expect(setup.server.activityWindows[0]).toMatchObject({ resets: 20, meanGapMs: 30000, variationBp: 0 });
        expect(Object.keys(setup.server.activityWindows[0] as object).sort()).toEqual(['meanGapMs', 'resets', 'variationBp', 'windowEnd', 'windowStart']);
    });

    it('sends nothing while the workspace has it off', async () => {
        const setup = setUp();
        await readyWithProject(setup);
        await setup.tracker.start();

        for (let second = 0; second <= 700; second++) {
            setup.tracker.sampleActivity(second % 30);
        }

        expect(setup.server.activityWindows).toHaveLength(0);
    });
});

