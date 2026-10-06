/**
 * Shapes shared by the main process and the window. The window only ever
 * sees AppState: no token, no credentials, no internal ids.
 */

export type TimerState = 'running' | 'paused' | 'lunch' | 'stopped';

/** The timer as SprintSync's API returns it (TimerData). */
export interface ServerTimer {
    public_id: string;
    state: TimerState;
    project: { public_id: string | null; name: string };
    task: { public_id: string | null; title: string } | null;
    started_at: string;
    running_since: string | null;
    /** On (paid) lunch since. */
    lunch_started_at: string | null;
    /** "idle" after an automatic inactivity pause. */
    pause_reason: string | null;
    accumulated_seconds: number;
    elapsed_seconds: number;
    longest_segment_seconds: number;
    billable: boolean;
    note: string | null;
    server_time: string;
    entry_public_id: string | null;
}

export interface ProjectOption {
    public_id: string;
    name: string;
    tasks: { public_id: string; title: string }[];
}

export interface WorkspaceOption {
    slug: string;
    name: string;
    has_profile: boolean;
}

export type TimerAction = 'pause' | 'resume' | 'stop' | 'lunch_start' | 'lunch_end' | 'idle_pause';

/** A timer change waiting to reach SprintSync. Never holds credentials. */
export interface PendingOperation {
    /** Client-generated key, kept for every retry so SprintSync applies it once. */
    id: string;
    action: TimerAction;
    workspace: string;
    session: string;
    /** When the person pressed the button (or idle was detected), in SprintSync's clock (ISO). */
    occurredAt: string;
    /** idle_pause only: the computer's last activity, in SprintSync's clock (ISO). */
    idleSince?: string;
    createdAt: string;
}

/** The workspace's time rules, from SprintSync (never set locally). */
export interface TimePolicy {
    version: string | null;
    idle_enabled: boolean;
    idle_seconds: number | null;
    weekly_target_seconds: number | null;
    lunch_enabled: boolean;
    lunch_allowance_seconds: number | null;
    overtime_mode: string;
    integrity_enabled: boolean;
}

export interface IdlePeriod {
    public_id: string;
    started_at: string;
    ended_at: string | null;
    seconds: number;
    threshold_seconds: number;
    decision: 'pending' | 'kept' | 'discarded';
}

export interface DayBreakdown {
    worked: number;
    lunch_paid: number;
    holiday: number;
    paid_leave: number;
    compensated: number;
}

export interface Notice {
    kind: 'info' | 'warning' | 'error';
    text: string;
}

export interface AppState {
    phase: 'loading' | 'signed-out' | 'ready';
    user: { name: string; email: string } | null;
    workspaces: WorkspaceOption[];
    workspace: string | null;
    hasProfile: boolean;
    projects: ProjectOption[];
    selection: { project: string | null; task: string | null };
    /** The timer as shown: SprintSync's, with any not-yet-synced changes applied. */
    timer: ServerTimer | null;
    todaySeconds: number;
    today: DayBreakdown | null;
    policy: TimePolicy | null;
    /** Idle pauses waiting for "keep as work" or "don't count it". */
    idlePeriods: IdlePeriod[];
    online: boolean;
    pending: number;
    busy: boolean;
    /** Milliseconds to add to this computer's clock to get SprintSync's. */
    clockOffsetMs: number;
    notice: Notice | null;
    signInError: string | null;
    preferences: { launchAtLogin: boolean; launchAtLoginSupported: boolean };
    serverUrl: string;
    /** This build's version and release line, for Settings. */
    app: { version: string; channel: 'stable' | 'uat' };
}
