<script setup lang="ts">
import { CalendarPlus, Clock, Coffee, History, Loader2, Pause, Play, Plus, Settings2, Square, Timer, UserRound } from 'lucide-vue-next';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatClock, formatDuration, formatPeriod, formatTimeOfDay, requestKey } from '@/lib/time';
import { type BreadcrumbItem } from '@/types';
import type { TimeEntryData, TimerData } from '@/types/generated';

type ProjectOption = { public_id: string; name: string; tasks: { public_id: string; title: string }[] };
type PersonRef = { public_id: string; name: string };

const props = defineProps<{
    canManageTeam: boolean;
    /** Your own Team profile; null when your account isn't linked to one. */
    me: PersonRef | null;
    /** Whose time is shown (you, or anyone for the owner). */
    viewing: PersonRef | null;
    isSelf: boolean;
    people: PersonRef[];
    timer: TimerData | null;
    projects: ProjectOption[];
    /** Recorded seconds for the person shown, by the workspace's calendar. */
    totals: { today: number; week: number; month: number };
    entries: TimeEntryData[];
    today: string;
    timezone: string;
    /** The workspace's time rules that apply to everyone (shown so nothing is hidden). */
    policy: {
        idle_enabled: boolean;
        idle_seconds: number | null;
        weekly_target_seconds: number | null;
        lunch_enabled: boolean;
        lunch_allowance_seconds: number | null;
        overtime_mode: string;
        integrity_enabled: boolean;
    };
    breakdown: { today: Breakdown; week: Breakdown } | null;
    idlePeriods: {
        public_id: string;
        started_at: string;
        ended_at: string | null;
        seconds: number;
        threshold_seconds: number;
        decision: 'pending' | 'kept' | 'discarded';
        reason: string | null;
    }[];
    /** Owner: things to look at. */
    reviews: {
        public_id: string;
        kind: 'lunch_excess' | 'idle_kept' | 'activity';
        person: string;
        summary: string;
        work_date: string;
        seconds: number | null;
        decisions: string[];
    }[];
    /** Owner: this week for everyone. */
    team: ({ public_id: string; name: string } & Breakdown)[];
}>();

type Breakdown = {
    worked: number;
    billable: number;
    lunch_paid: number;
    lunch_excess_open: number;
    holiday: number;
    paid_leave: number;
    unpaid_leave_days: number;
    compensated: number;
    outside_schedule: number;
    worked_on_holiday: number;
    target: number | null;
    over_target: number;
};

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Time', href: workspaceRoute('workspace.time.index') }];

/* ---- Live timer: elapsed = accumulated + (now − running_since), using the server's clock offset ---- */

const offset = computed(() => (props.timer ? new Date(props.timer.server_time).getTime() - Date.now() : 0));
const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(tick));

const elapsed = computed(() => {
    const timer = props.timer;

    if (!timer) return 0;
    if (timer.state !== 'running' || !timer.running_since) return timer.accumulated_seconds;

    return timer.accumulated_seconds + (now.value + offset.value - new Date(timer.running_since).getTime()) / 1000;
});

/* ---- Starting a timer ---- */

const startForm = useForm({ project: props.projects[0]?.public_id ?? '', task: '', note: '', billable: true, operation_key: '' });
const tasksFor = (project: string) => props.projects.find((option) => option.public_id === project)?.tasks ?? [];
watch(
    () => startForm.project,
    () => (startForm.task = ''),
);

function start() {
    startForm.operation_key = requestKey();
    startForm.post(workspaceRoute('workspace.time.timer.start'), { preserveScroll: true, onSuccess: () => startForm.reset('note') });
}

const busy = ref(false);

const lunchElapsed = computed(() =>
    props.timer?.lunch_started_at ? Math.max(0, (now.value + offset.value - new Date(props.timer.lunch_started_at).getTime()) / 1000) : 0,
);
const lunchOver = computed(() => !!props.policy.lunch_allowance_seconds && lunchElapsed.value > props.policy.lunch_allowance_seconds);

/* ---- Idle periods: keep as work (with a reason) or drop ---- */

const keeping = ref<string | null>(null);
const keepForm = useForm({ reason: '' });

function keepIdle(publicId: string) {
    keepForm.post(workspaceRoute('workspace.time.idle.keep', { idlePeriod: publicId }), {
        preserveScroll: true,
        onSuccess: () => {
            keeping.value = null;
            keepForm.reset();
        },
    });
}

function dropIdle(publicId: string) {
    router.post(workspaceRoute('workspace.time.idle.drop', { idlePeriod: publicId }), {}, { preserveScroll: true });
}

/* ---- Owner reviews ---- */

const DECISION_LABELS: Record<string, string> = {
    counted: 'Count as paid + billable',
    not_counted: "Don't count",
    accepted: 'Accept',
    rejected: 'Reject',
    no_concern: 'No concern',
    follow_up: 'Follow up',
};

function resolveReview(publicId: string, decision: string) {
    router.post(workspaceRoute('workspace.time.reviews.resolve', { review: publicId }), { decision }, { preserveScroll: true });
}

/* ---- Plain-language policy, shown to everyone it applies to ---- */

const policyNotice = computed(() => {
    const parts: string[] = [];

    if (props.policy.idle_enabled && props.policy.idle_seconds)
        parts.push(`SprintSync Desktop pauses timers after ${Math.round(props.policy.idle_seconds / 60)} minutes of inactivity`);
    if (props.policy.lunch_enabled && props.policy.lunch_allowance_seconds)
        parts.push(`lunch is paid up to ${formatDuration(props.policy.lunch_allowance_seconds)} a day`);
    if (props.policy.integrity_enabled) parts.push('activity integrity checks are on (unusually repetitive activity is flagged for review)');

    return parts.length ? `This workspace: ${parts.join('; ')}. SprintSync never records screenshots, keystrokes, websites or applications.` : null;
});

function timerAction(action: 'pause' | 'resume' | 'stop' | 'lunch-start' | 'lunch-end') {
    if (busy.value) return;

    busy.value = true;
    router.post(
        workspaceRoute(`workspace.time.timer.${action}`),
        { operation_key: requestKey(), session: props.timer?.public_id },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

/* ---- Owner: switch person ---- */

function viewPerson(event: Event) {
    const person = (event.target as HTMLSelectElement).value;
    router.get(workspaceRoute('workspace.time.index'), person ? { person } : {}, { preserveScroll: true });
}

/* ---- Add time for a day ---- */

const addOpen = ref(false);
const addForm = useForm({
    person: '',
    project: props.projects[0]?.public_id ?? '',
    task: '',
    date: props.today,
    hours: '',
    billable: true,
    note: '',
    idempotency_key: '',
});
watch(
    () => addForm.project,
    () => (addForm.task = ''),
);

function openAdd() {
    addForm.reset();
    addForm.clearErrors();
    addForm.person = props.isSelf ? '' : (props.viewing?.public_id ?? '');
    /* One key per opened form: a double submit is the same request, not two. */
    addForm.idempotency_key = requestKey();
    addOpen.value = true;
}

function saveAdd() {
    addForm.post(workspaceRoute('workspace.time.entries.store'), { preserveScroll: true, onSuccess: () => (addOpen.value = false) });
}

/* ---- Owner: correct or void an entry ---- */

const correcting = ref<TimeEntryData | null>(null);
const correctForm = useForm({ hours: '', reason: '', idempotency_key: '' });

function openCorrect(entry: TimeEntryData) {
    correctForm.reset();
    correctForm.clearErrors();
    correctForm.idempotency_key = requestKey();
    correcting.value = entry;
}

function saveCorrect() {
    if (!correcting.value) return;

    correctForm.post(workspaceRoute('workspace.time.entries.adjust', { timeEntry: correcting.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => (correcting.value = null),
    });
}

const voiding = ref<TimeEntryData | null>(null);
const voidForm = useForm({ reason: '' });

function saveVoid() {
    if (!voiding.value) return;

    voidForm.post(workspaceRoute('workspace.time.entries.void', { timeEntry: voiding.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => {
            voiding.value = null;
            voidForm.reset();
        },
    });
}

function entryActions(entry: TimeEntryData): DropdownEntry[] {
    const actions: DropdownEntry[] = [];

    if (!entry.voided && (entry.source === 'tracked' || entry.source === 'manual'))
        actions.push({ label: 'Correct hours', icon: Clock, onSelect: () => openCorrect(entry) });
    if (!entry.voided && (entry.source === 'manual' || entry.source === 'adjustment'))
        actions.push({ label: 'Void', icon: History, onSelect: () => (voiding.value = entry) });

    return actions;
}

/** "9:12 AM – 12:30 PM" for tracked time, otherwise the day or period it belongs to. */
function when(entry: TimeEntryData): string {
    if (entry.started_at && entry.ended_at) {
        return `${formatPeriod(entry.period_start, entry.period_start)} · ${formatTimeOfDay(entry.started_at, props.timezone)} – ${formatTimeOfDay(entry.ended_at, props.timezone)}`;
    }

    return formatPeriod(entry.period_start, entry.period_end);
}

function origin(entry: TimeEntryData): string {
    if (entry.source === 'tracked') return 'Tracked';
    if (entry.source === 'lunch' && entry.adjusts_entry_public_id) return 'Lunch past the allowance, counted by the owner';
    if (entry.source === 'lunch') return 'Paid lunch · counts as paid and billable up to the allowance';
    if (entry.source === 'idle_kept') return 'Idle time kept as work';
    if (entry.adjusts_entry_public_id) return `Correction${entry.created_by ? ` by ${entry.created_by}` : ''}`;
    if (entry.source === 'adjustment') return `Previous hours${entry.created_by ? ` added by ${entry.created_by}` : ''}`;

    return `Added manually${entry.created_by ? ` by ${entry.created_by}` : ''}`;
}
</script>

<template>
    <Head title="Time" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Work" title="Time" description="Track time on your projects, or add time you worked without a timer." />

            <!-- Owner: whose time, and previous hours -->
            <div v-if="canManageTeam" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <label class="inline-flex items-center gap-2 text-sm">
                    <UserRound class="text-muted-foreground size-4" />
                    <span class="text-muted-foreground">Showing</span>
                    <select
                        class="bg-background h-9 rounded-md border px-2 text-sm"
                        :value="isSelf ? '' : (viewing?.public_id ?? '')"
                        data-testid="person-select"
                        @change="viewPerson"
                    >
                        <option v-if="me" value="">You ({{ me.name }})</option>
                        <option v-else value="" disabled>Choose someone</option>
                        <option
                            v-for="person in people.filter((p) => p.public_id !== me?.public_id)"
                            :key="person.public_id"
                            :value="person.public_id"
                        >
                            {{ person.name }}
                        </option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" class="gap-1.5" as-child>
                        <Link :href="workspaceRoute('workspace.time.settings.edit')" data-testid="time-settings-link">
                            <Settings2 class="size-4" />
                            Time settings
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" class="gap-1.5" as-child>
                        <Link :href="workspaceRoute('workspace.time.previous-hours.create')" data-testid="previous-hours-link">
                            <CalendarPlus class="size-4" />
                            Add previous hours
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Owner: what needs a look -->
            <section v-if="canManageTeam && reviews.length" class="bg-card rounded-2xl border" data-testid="reviews">
                <h2 class="text-muted-foreground border-b px-4 py-3 text-[11px] font-semibold tracking-[0.12em] uppercase">To review</h2>
                <ul class="divide-y">
                    <li v-for="review in reviews" :key="review.public_id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">{{ review.person }} · {{ formatPeriod(review.work_date, review.work_date) }}</p>
                            <p class="text-muted-foreground text-sm">{{ review.summary }}</p>
                        </div>
                        <div class="flex gap-2">
                            <Button
                                v-for="decision in review.decisions"
                                :key="decision"
                                variant="outline"
                                size="sm"
                                :data-testid="`review-${decision}`"
                                @click="resolveReview(review.public_id, decision)"
                            >
                                {{ DECISION_LABELS[decision] ?? decision }}
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Owner: the team this week -->
            <section v-if="canManageTeam && team.length" class="bg-card overflow-x-auto rounded-2xl border" data-testid="team">
                <h2 class="text-muted-foreground border-b px-4 py-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Team this week</h2>
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-muted-foreground text-xs">
                        <tr class="border-b">
                            <th class="px-4 py-2 text-left font-medium">Person</th>
                            <th class="px-2 py-2 text-right font-medium">Worked</th>
                            <th class="px-2 py-2 text-right font-medium">Paid lunch</th>
                            <th class="px-2 py-2 text-right font-medium">Leave</th>
                            <th class="px-2 py-2 text-right font-medium">Holiday</th>
                            <th class="px-2 py-2 text-right font-medium">Paid time</th>
                            <th class="px-4 py-2 text-right font-medium">Over target</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y tabular-nums">
                        <tr v-for="row in team" :key="row.public_id">
                            <td class="px-4 py-2">
                                <Link :href="workspaceRoute('workspace.time.index', { person: row.public_id })" class="font-medium hover:underline">{{
                                    row.name
                                }}</Link>
                            </td>
                            <td class="px-2 py-2 text-right">{{ formatDuration(row.worked) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatDuration(row.lunch_paid) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatDuration(row.paid_leave) }}</td>
                            <td class="px-2 py-2 text-right">{{ formatDuration(row.holiday) }}</td>
                            <td class="px-2 py-2 text-right font-medium">{{ formatDuration(row.compensated) }}</td>
                            <td
                                class="px-4 py-2 text-right"
                                :class="row.over_target > 0 && policy.overtime_mode === 'review' && 'font-semibold text-amber-700'"
                            >
                                {{ row.target === null ? '—' : formatDuration(row.over_target) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-if="policyNotice" class="bg-muted/60 text-muted-foreground rounded-xl px-4 py-3 text-xs" data-testid="policy-notice">
                {{ policyNotice }}
                <template v-if="policy.idle_enabled">
                    Full idle detection requires SprintSync Desktop; the web timer can't tell whether your computer is in use.
                    <a
                        :href="`${route('home')}#desktop`"
                        class="text-foreground font-medium whitespace-nowrap underline underline-offset-2"
                        data-testid="get-desktop"
                        >Get SprintSync Desktop</a
                    ></template
                >
            </p>

            <div v-if="!viewing" class="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm" data-testid="no-profile">
                Your account isn't linked to a Team profile yet, so there's no time to show for you.
                <template v-if="canManageTeam"> Link your account in Team, or choose someone above.</template>
                <template v-else> Ask the workspace owner to link your account to your Team profile.</template>
            </div>

            <template v-else>
                <!-- Timer -->
                <section v-if="isSelf" class="bg-card rounded-3xl border p-5 sm:p-6" data-testid="timer">
                    <template v-if="timer">
                        <!-- Lunch: a paid break, its own state -->
                        <div v-if="timer.state === 'lunch'" class="text-center" data-testid="lunch">
                            <p class="text-sm font-semibold">Lunch break</p>
                            <p class="text-muted-foreground text-xs">
                                Paid break<template v-if="policy.lunch_allowance_seconds">
                                    · Allowed {{ formatDuration(policy.lunch_allowance_seconds) }}</template
                                >
                            </p>
                            <p
                                class="mt-3 text-4xl font-semibold tracking-tight tabular-nums"
                                :class="lunchOver && 'text-amber-600'"
                                data-testid="lunch-clock"
                            >
                                {{ formatClock(lunchElapsed) }}
                            </p>
                            <p v-if="lunchOver" class="mt-1 text-xs text-amber-700">
                                Past the paid lunch allowance. The extra only counts if the owner decides it should.
                            </p>
                            <Button class="mt-5 gap-1.5" :disabled="busy" data-testid="end-lunch" @click="timerAction('lunch-end')">End lunch</Button>
                        </div>

                        <template v-else>
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 text-sm">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="timer.state === 'running' ? 'animate-pulse bg-emerald-500' : 'bg-amber-500'"
                                        />
                                        <span class="font-medium" data-testid="timer-state">{{
                                            timer.state === 'running' ? 'Running' : 'Paused'
                                        }}</span>
                                    </p>
                                    <p class="mt-1 truncate font-semibold">
                                        {{ timer.project.name
                                        }}<span v-if="timer.task" class="text-muted-foreground font-normal"> · {{ timer.task.title }}</span>
                                    </p>
                                    <p class="text-muted-foreground text-xs">
                                        Started {{ formatTimeOfDay(timer.started_at, timezone)
                                        }}<template v-if="!timer.billable"> · Non-billable</template>
                                    </p>
                                </div>
                                <p class="text-4xl font-semibold tracking-tight tabular-nums" data-testid="timer-clock">{{ formatClock(elapsed) }}</p>
                            </div>
                            <p
                                v-if="timer.state === 'paused' && timer.pause_reason === 'idle'"
                                class="mt-4 rounded-xl bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-300"
                                data-testid="idle-paused"
                            >
                                Timer paused after {{ policy.idle_seconds ? Math.round(policy.idle_seconds / 60) : '' }} minutes of inactivity.
                                Resume, or review the idle time below.
                            </p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <Button
                                    v-if="timer.state === 'running' && policy.lunch_enabled"
                                    variant="outline"
                                    class="gap-1.5"
                                    :disabled="busy"
                                    data-testid="start-lunch"
                                    @click="timerAction('lunch-start')"
                                >
                                    <Coffee class="size-4" />
                                    Lunch
                                </Button>
                                <Button
                                    v-if="timer.state === 'running'"
                                    variant="outline"
                                    class="gap-1.5"
                                    :disabled="busy"
                                    data-testid="pause-timer"
                                    @click="timerAction('pause')"
                                >
                                    <Pause class="size-4" />
                                    Pause
                                </Button>
                                <Button
                                    v-else
                                    variant="outline"
                                    class="gap-1.5"
                                    :disabled="busy"
                                    data-testid="resume-timer"
                                    @click="timerAction('resume')"
                                >
                                    <Play class="size-4" />
                                    Resume
                                </Button>
                                <Button class="gap-1.5" :disabled="busy" data-testid="stop-timer" @click="timerAction('stop')">
                                    <Loader2 v-if="busy" class="size-4 animate-spin" />
                                    <Square v-else class="size-4" />
                                    Stop
                                </Button>
                            </div>
                        </template>
                    </template>

                    <form v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="start">
                        <div class="grid gap-1.5">
                            <Label for="timer-project">Project</Label>
                            <select
                                id="timer-project"
                                v-model="startForm.project"
                                class="bg-background h-10 rounded-md border px-3 text-sm"
                                data-testid="timer-project"
                            >
                                <option v-for="project in projects" :key="project.public_id" :value="project.public_id">{{ project.name }}</option>
                            </select>
                            <InputError :message="startForm.errors.project" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="timer-task">Task <span class="text-muted-foreground font-normal">(optional)</span></Label>
                            <select
                                id="timer-task"
                                v-model="startForm.task"
                                class="bg-background h-10 rounded-md border px-3 text-sm"
                                data-testid="timer-task"
                            >
                                <option value="">No task</option>
                                <option v-for="task in tasksFor(startForm.project)" :key="task.public_id" :value="task.public_id">
                                    {{ task.title }}
                                </option>
                            </select>
                            <InputError :message="startForm.errors.task" />
                        </div>
                        <AppFormInput
                            id="timer-note"
                            v-model="startForm.note"
                            class="sm:col-span-2"
                            label="What are you working on?"
                            hint="Optional"
                            :error="startForm.errors.note"
                        />
                        <div class="flex items-center justify-between gap-3 sm:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <AppSwitch id="timer-billable" v-model="startForm.billable" label="Billable" />
                                Billable
                            </label>
                            <Button type="submit" class="gap-1.5" :disabled="startForm.processing || !projects.length" data-testid="start-timer">
                                <Timer class="size-4" />
                                Start timer
                            </Button>
                        </div>
                        <p v-if="!projects.length" class="text-muted-foreground text-sm sm:col-span-2">You aren't on any projects yet.</p>
                    </form>
                </section>

                <p v-else-if="timer" class="bg-card rounded-2xl border p-4 text-sm" data-testid="other-timer">
                    {{ viewing.name }} has a timer {{ timer.state === 'running' ? 'running' : 'paused' }} on {{ timer.project.name }} ·
                    {{ formatClock(elapsed) }}
                </p>

                <!-- Totals -->
                <div class="grid grid-cols-3 gap-3" data-testid="totals">
                    <div
                        v-for="tile in [
                            { key: 'today', label: 'Today', value: totals.today },
                            { key: 'week', label: 'This week', value: totals.week },
                            { key: 'month', label: 'This month', value: totals.month },
                        ]"
                        :key="tile.key"
                        class="bg-card rounded-2xl border p-4"
                        :data-testid="`total-${tile.key}`"
                    >
                        <p class="text-muted-foreground text-xs sm:text-sm">{{ tile.label }}</p>
                        <p class="mt-1 text-lg font-semibold tracking-tight tabular-nums sm:text-2xl">{{ formatDuration(tile.value) }}</p>
                    </div>
                </div>

                <!-- Worked vs paid, never one blended number -->
                <section v-if="breakdown" class="grid gap-3 sm:grid-cols-2" data-testid="breakdown">
                    <div class="bg-card rounded-2xl border p-4 text-sm">
                        <p class="mb-2 font-semibold">Today</p>
                        <dl class="space-y-1 tabular-nums">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Worked</dt>
                                <dd>{{ formatDuration(breakdown.today.worked) }}</dd>
                            </div>
                            <div v-if="breakdown.today.lunch_paid" class="flex justify-between">
                                <dt class="text-muted-foreground">Paid lunch</dt>
                                <dd>{{ formatDuration(breakdown.today.lunch_paid) }}</dd>
                            </div>
                            <div v-if="breakdown.today.holiday" class="flex justify-between">
                                <dt class="text-muted-foreground">Holiday</dt>
                                <dd>{{ formatDuration(breakdown.today.holiday) }}</dd>
                            </div>
                            <div v-if="breakdown.today.paid_leave" class="flex justify-between">
                                <dt class="text-muted-foreground">Paid leave</dt>
                                <dd>{{ formatDuration(breakdown.today.paid_leave) }}</dd>
                            </div>
                            <div class="flex justify-between border-t pt-1 font-medium">
                                <dt>Paid time</dt>
                                <dd>{{ formatDuration(breakdown.today.compensated) }}</dd>
                            </div>
                            <div class="flex justify-between" data-testid="billable-today">
                                <dt class="text-muted-foreground">Billable</dt>
                                <dd>{{ formatDuration(breakdown.today.billable) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="bg-card rounded-2xl border p-4 text-sm">
                        <p class="mb-2 font-semibold">This week</p>
                        <dl class="space-y-1 tabular-nums">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Worked</dt>
                                <dd>{{ formatDuration(breakdown.week.worked) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Paid lunch</dt>
                                <dd>{{ formatDuration(breakdown.week.lunch_paid) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Paid leave</dt>
                                <dd>{{ formatDuration(breakdown.week.paid_leave) }}</dd>
                            </div>
                            <div v-if="breakdown.week.holiday" class="flex justify-between">
                                <dt class="text-muted-foreground">Holiday</dt>
                                <dd>{{ formatDuration(breakdown.week.holiday) }}</dd>
                            </div>
                            <div class="flex justify-between border-t pt-1 font-medium">
                                <dt>Paid time</dt>
                                <dd>{{ formatDuration(breakdown.week.compensated) }}</dd>
                            </div>
                            <div class="flex justify-between" data-testid="billable-week">
                                <dt class="text-muted-foreground">Billable</dt>
                                <dd>{{ formatDuration(breakdown.week.billable) }}</dd>
                            </div>
                            <div v-if="breakdown.week.target !== null" class="flex justify-between" data-testid="over-target">
                                <dt class="text-muted-foreground">Over weekly target ({{ formatDuration(breakdown.week.target) }})</dt>
                                <dd>{{ formatDuration(breakdown.week.over_target) }}</dd>
                            </div>
                            <div v-if="breakdown.week.outside_schedule" class="flex justify-between">
                                <dt class="text-muted-foreground">Outside scheduled hours</dt>
                                <dd>{{ formatDuration(breakdown.week.outside_schedule) }}</dd>
                            </div>
                            <div v-if="breakdown.week.lunch_excess_open" class="flex justify-between text-amber-700">
                                <dt>Lunch past the paid time (waiting for review)</dt>
                                <dd>{{ formatDuration(breakdown.week.lunch_excess_open) }}</dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <!-- Idle time: keep as work (with a reason) or drop -->
                <section
                    v-if="idlePeriods.some((period) => period.decision === 'pending')"
                    class="bg-card rounded-2xl border"
                    data-testid="idle-periods"
                >
                    <h2 class="text-muted-foreground border-b px-4 py-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Idle time</h2>
                    <ul class="divide-y">
                        <li
                            v-for="period in idlePeriods.filter((item) => item.decision === 'pending')"
                            :key="period.public_id"
                            class="grid gap-2 px-4 py-3 text-sm"
                        >
                            <p>
                                <span class="font-medium"
                                    >{{ formatTimeOfDay(period.started_at, timezone)
                                    }}{{ period.ended_at ? ` – ${formatTimeOfDay(period.ended_at, timezone)}` : ' (still paused)' }}</span
                                >
                                <span class="text-muted-foreground"
                                    ><template v-if="period.ended_at"> · {{ formatDuration(period.seconds) }}</template> without activity. Not counted
                                    as work unless you keep it.</span
                                >
                            </p>
                            <form
                                v-if="isSelf && keeping === period.public_id"
                                class="flex flex-col gap-2 sm:flex-row"
                                @submit.prevent="keepIdle(period.public_id)"
                            >
                                <input
                                    v-model="keepForm.reason"
                                    class="h-9 flex-1 rounded-md border px-2"
                                    placeholder="What was it? e.g. Client phone call"
                                    data-testid="idle-reason"
                                />
                                <Button type="submit" size="sm" :disabled="keepForm.processing">Keep as work</Button>
                            </form>
                            <InputError v-if="keeping === period.public_id" :message="keepForm.errors.reason" />
                            <div v-if="isSelf && keeping !== period.public_id" class="flex gap-2">
                                <Button size="sm" variant="outline" data-testid="keep-idle" @click="keeping = period.public_id">Keep as work…</Button>
                                <Button size="sm" variant="ghost" :disabled="!period.ended_at" @click="dropIdle(period.public_id)"
                                    >Don't count it</Button
                                >
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Entries -->
                <section>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.12em] uppercase">Recent time</h2>
                        <Button variant="outline" size="sm" class="gap-1.5" data-testid="add-time" @click="openAdd">
                            <Plus class="size-4" />
                            Add time
                        </Button>
                    </div>
                    <div class="bg-card overflow-hidden rounded-2xl border">
                        <AppEmptyState v-if="!entries.length" title="No time yet" description="Start a timer, or add time you worked without one.">
                            <template #icon><Clock class="size-5" /></template>
                        </AppEmptyState>
                        <ul v-else class="divide-y" data-testid="entries">
                            <li
                                v-for="entry in entries"
                                :key="entry.public_id"
                                class="flex items-start gap-3 px-4 py-3.5 sm:px-5"
                                :class="entry.voided && 'opacity-60'"
                                :data-source="entry.source"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium" :class="entry.voided && 'line-through'">
                                        {{ entry.project_name
                                        }}<span v-if="entry.task_title" class="text-muted-foreground font-normal"> · {{ entry.task_title }}</span>
                                    </p>
                                    <p class="text-muted-foreground text-sm">{{ when(entry) }}</p>
                                    <p class="text-muted-foreground mt-0.5 text-xs">
                                        {{ origin(entry) }}<template v-if="!entry.billable"> · Non-billable</template
                                        ><template v-if="entry.note"> · {{ entry.note }}</template
                                        ><template v-if="entry.voided"> · Voided: {{ entry.void_reason }}</template>
                                    </p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums" :class="entry.duration_seconds < 0 && 'text-destructive'">
                                    {{ formatDuration(entry.duration_seconds) }}
                                </span>
                                <AppDropDown
                                    v-if="canManageTeam && entryActions(entry).length"
                                    :items="entryActions(entry)"
                                    align="end"
                                    trigger-label="Time entry actions"
                                />
                            </li>
                        </ul>
                    </div>
                </section>
            </template>
        </div>

        <!-- Add time for one day -->
        <AppModal v-model:open="addOpen" title="Add time" description="Time worked without a timer. No start or end time is needed.">
            <form id="add-time-form" class="grid gap-4" @submit.prevent="saveAdd">
                <div v-if="canManageTeam" class="grid gap-1.5">
                    <Label for="add-person">Person</Label>
                    <select id="add-person" v-model="addForm.person" class="bg-background h-10 rounded-md border px-3 text-sm">
                        <option v-if="me" value="">You ({{ me.name }})</option>
                        <option
                            v-for="person in people.filter((p) => p.public_id !== me?.public_id)"
                            :key="person.public_id"
                            :value="person.public_id"
                        >
                            {{ person.name }}
                        </option>
                    </select>
                    <InputError :message="addForm.errors.person" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="add-project">Project</Label>
                    <select id="add-project" v-model="addForm.project" class="bg-background h-10 rounded-md border px-3 text-sm">
                        <option v-for="project in projects" :key="project.public_id" :value="project.public_id">{{ project.name }}</option>
                    </select>
                    <InputError :message="addForm.errors.project" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="add-task">Task <span class="text-muted-foreground font-normal">(optional)</span></Label>
                    <select id="add-task" v-model="addForm.task" class="bg-background h-10 rounded-md border px-3 text-sm">
                        <option value="">No task</option>
                        <option v-for="task in tasksFor(addForm.project)" :key="task.public_id" :value="task.public_id">{{ task.title }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <AppFormInput id="add-date" v-model="addForm.date" type="date" label="Day" :error="addForm.errors.date" required />
                    <AppFormInput id="add-hours" v-model="addForm.hours" label="Hours" placeholder="2.5" :error="addForm.errors.hours" required />
                </div>
                <AppFormInput id="add-note" v-model="addForm.note" label="Note" hint="Optional" :error="addForm.errors.note" />
                <label class="inline-flex items-center gap-2 text-sm">
                    <AppSwitch id="add-billable" v-model="addForm.billable" label="Billable" />
                    Billable
                </label>
            </form>
            <template #footer>
                <Button variant="outline" @click="addOpen = false">Cancel</Button>
                <Button type="submit" form="add-time-form" :disabled="addForm.processing" data-testid="save-time">Save time</Button>
            </template>
        </AppModal>

        <!-- Correct an entry (the original stays as it was) -->
        <AppModal
            :open="correcting !== null"
            title="Correct hours"
            description="Add or remove hours with a reason. The original time is kept, so the change is visible."
            @update:open="(open) => !open && (correcting = null)"
        >
            <form id="correct-form" class="grid gap-4" @submit.prevent="saveCorrect">
                <p v-if="correcting" class="text-sm">
                    {{ correcting.project_name }} · {{ when(correcting) }} ·
                    <span class="font-medium">{{ formatDuration(correcting.duration_seconds) }}</span>
                </p>
                <AppFormInput
                    id="correct-hours"
                    v-model="correctForm.hours"
                    label="Hours to add or remove"
                    placeholder="-0.5"
                    hint="Use a minus sign to remove"
                    :error="correctForm.errors.hours"
                    required
                />
                <AppFormInput
                    id="correct-reason"
                    v-model="correctForm.reason"
                    label="Reason"
                    placeholder="Forgot to stop the timer at lunch"
                    :error="correctForm.errors.reason"
                    required
                />
            </form>
            <template #footer>
                <Button variant="outline" @click="correcting = null">Cancel</Button>
                <Button type="submit" form="correct-form" :disabled="correctForm.processing">Save correction</Button>
            </template>
        </AppModal>

        <AppModal
            :open="voiding !== null"
            title="Void this time?"
            description="It stays on record but no longer counts."
            @update:open="(open) => !open && (voiding = null)"
        >
            <form id="void-form" @submit.prevent="saveVoid">
                <AppFormInput id="void-reason" v-model="voidForm.reason" label="Reason" :error="voidForm.errors.reason" required />
            </form>
            <template #footer>
                <Button variant="outline" @click="voiding = null">Cancel</Button>
                <Button type="submit" form="void-form" variant="destructive" :disabled="voidForm.processing">Void</Button>
            </template>
        </AppModal>
    </AppLayout>
</template>
