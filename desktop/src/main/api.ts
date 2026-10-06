import type { DayBreakdown, IdlePeriod, ProjectOption, ServerTimer, TimePolicy, TimerAction, WorkspaceOption } from '../shared/types';
import type { ActivityWindow } from './activity';

/** No answer from SprintSync (offline, timeout, DNS). Safe to retry with the same operation key. */
export class NetworkError extends Error {
    constructor(message = "Can't reach SprintSync.") {
        super(message);
        this.name = 'NetworkError';
    }
}

/** SprintSync answered with an error. `code` is its typed error code, when it sent one. */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string | null,
        message: string,
        public readonly fields: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

export interface Profile {
    user: { name: string; email: string };
    workspaces: WorkspaceOption[];
}

export interface Overview {
    has_profile: boolean;
    projects: ProjectOption[];
    timer: ServerTimer | null;
    today_seconds: number;
    today: string;
    timezone: string;
    server_time: string;
    policy: TimePolicy;
    today_breakdown: DayBreakdown | null;
    idle_periods: IdlePeriod[];
}

const ACTION_PATHS: Record<TimerAction, string> = {
    pause: 'pause',
    resume: 'resume',
    stop: 'stop',
    lunch_start: 'lunch-start',
    lunch_end: 'lunch-end',
    idle_pause: 'idle-pause',
};

export type FetchLike = (input: string, init: RequestInit) => Promise<Response>;

/**
 * SprintSync's desktop API. The token is read when a request is made and sent
 * only as a Bearer header; it is never part of an error or a log line.
 */
export class SprintSyncApi {
    constructor(
        private readonly baseUrl: string,
        private readonly fetchImpl: FetchLike,
        private readonly token: () => string | null,
        private readonly timeoutMs = 10_000,
    ) {}

    signIn(email: string, password: string, deviceName: string, platform: string): Promise<Profile & { token: string }> {
        return this.request('POST', '/api/desktop/sign-in', { email, password, device_name: deviceName, platform }, false);
    }

    signOut(): Promise<unknown> {
        return this.request('POST', '/api/desktop/sign-out');
    }

    me(): Promise<Profile> {
        return this.request('GET', '/api/desktop/me');
    }

    overview(workspace: string): Promise<Overview> {
        return this.request('GET', `/api/desktop/${encodeURIComponent(workspace)}/time/overview`);
    }

    start(workspace: string, body: { project: string; task: string | null; operation_key: string }): Promise<{ timer: ServerTimer }> {
        return this.request('POST', `/api/desktop/${encodeURIComponent(workspace)}/time/timer/start`, body);
    }

    change(workspace: string, action: TimerAction, body: { operation_key: string; session: string; occurred_at: string; idle_since?: string }): Promise<{ timer: ServerTimer }> {
        return this.request('POST', `/api/desktop/${encodeURIComponent(workspace)}/time/timer/${ACTION_PATHS[action]}`, body);
    }

    keepIdle(workspace: string, idlePeriod: string, reason: string): Promise<unknown> {
        return this.request('POST', `/api/desktop/${encodeURIComponent(workspace)}/time/idle/${encodeURIComponent(idlePeriod)}/keep`, { reason });
    }

    dropIdle(workspace: string, idlePeriod: string): Promise<unknown> {
        return this.request('POST', `/api/desktop/${encodeURIComponent(workspace)}/time/idle/${encodeURIComponent(idlePeriod)}/drop`, {});
    }

    activity(workspace: string, window: ActivityWindow, clockOffsetMs: number): Promise<unknown> {
        return this.request('POST', `/api/desktop/${encodeURIComponent(workspace)}/time/activity`, {
            window_start: new Date(window.windowStart + clockOffsetMs).toISOString(),
            window_end: new Date(window.windowEnd + clockOffsetMs).toISOString(),
            resets: window.resets,
            mean_gap_ms: window.meanGapMs,
            variation_bp: window.variationBp,
        });
    }

    private async request<T>(method: string, path: string, body?: unknown, authenticated = true): Promise<T> {
        const headers: Record<string, string> = { Accept: 'application/json' };
        const token = authenticated ? this.token() : null;

        if (token) headers.Authorization = `Bearer ${token}`;
        if (body !== undefined) headers['Content-Type'] = 'application/json';

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), this.timeoutMs);
        let response: Response;

        try {
            response = await this.fetchImpl(`${this.baseUrl}${path}`, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
                signal: controller.signal,
                redirect: 'error',
            });
        } catch {
            throw new NetworkError();
        } finally {
            clearTimeout(timer);
        }

        const payload = (await response.json().catch(() => null)) as Record<string, unknown> | null;

        if (!response.ok) {
            throw toApiError(response.status, payload);
        }

        return payload as T;
    }
}

function toApiError(status: number, payload: Record<string, unknown> | null): ApiError {
    const error = (payload?.error ?? null) as { code?: string; message?: string } | null;
    const fields = (payload?.errors ?? {}) as Record<string, string[]>;
    const message =
        error?.message ??
        Object.values(fields)[0]?.[0] ??
        (typeof payload?.message === 'string' ? payload.message : null) ??
        (status === 401 ? 'Please sign in again.' : 'SprintSync could not do that.');

    return new ApiError(status, error?.code ?? null, message, fields);
}
