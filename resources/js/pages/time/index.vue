<script setup lang="ts">
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    CalendarPlus,
    CircleCheck,
    Clock,
    Coffee,
    Eye,
    History,
    Hourglass,
    Loader2,
    MessageSquarePlus,
    Monitor,
    Pause,
    Play,
    Plus,
    Settings2,
    Square,
    Timer,
    TimerOff,
} from 'lucide-vue-next';
import type { Component } from 'vue';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatBusinessDate, formatClock, formatClockTime, formatDuration, formatPeriod, formatTimeOfDay, requestKey } from '@/lib/time';
import { zoneLabel } from '@/lib/timezones';
import { type BreadcrumbItem } from '@/types';
import type { TimeEntryData, TimerData } from '@/types/generated';

type ProjectOption = { public_id: string; name: string; tasks: { public_id: string; title: string }[] };
type PersonRef = { public_id: string; name: string };

const props = defineProps<{
    canManageTeam: boolean;
    scope: 'team' | 'person';
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
    /** The business day "Today" refers to: the running timer's workday during an overnight shift. */
    workday: { date: string; is_today: boolean; timezone_label: string; overnight_shift: { start: string; end: string } | null };
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
        kind: ReviewKind;
        person: string;
        summary: string;
        work_date: string;
        seconds: number | null;
        decisions: string[];
        entry: string | null;
        evidence: Record<string, string | number>;
    }[];
    /** Owner: this week for everyone. */
    team: ({ public_id: string; name: string; open_reviews: number } & Breakdown)[];
    nextNeedingReview: { public_id: string; name: string; open_reviews: number } | null;
}>();

type ReviewKind = 'lunch_excess' | 'idle_kept' | 'activity' | 'auto_stopped';

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

type Review = (typeof props.reviews)[number];
type Decision = { label: string; done: string; effect: (review: Review) => string };

const RECORD_ONLY = 'marked for follow-up, on record only. Nothing else happens.';
const FINAL = "Pay decisions are final; they can't be changed later.";

const formatShort = (seconds: number) =>
    Math.abs(seconds) < 3600 ? `${seconds < 0 ? '−' : ''}${Math.floor(Math.abs(seconds) / 60)}m` : formatDuration(seconds);

const REVIEW_KINDS: Record<
    ReviewKind,
    { label: string; icon: Component; impact: string; decisions: Record<string, Decision>; notes?: string[]; noPayChange?: boolean }
> = {
    lunch_excess: {
        label: 'Long lunch',
        icon: Coffee,
        impact: 'over allowance',
        decisions: {
            counted: {
                label: 'Count as paid + billable',
                done: 'counted as paid + billable',
                effect: (review) => `adds the extra ${formatShort(review.seconds ?? 0)} to paid and billable time, so the client is charged for it.`,
            },
            not_counted: {
                label: "Don't count",
                done: 'not counted',
                effect: (review) =>
                    review.evidence.allowance_seconds
                        ? `only the ${formatShort(Number(review.evidence.allowance_seconds))} allowance stays paid.`
                        : 'only the paid allowance counts.',
            },
        },
    },
    idle_kept: {
        label: 'Idle time kept as work',
        icon: Hourglass,
        impact: 'counted if accepted',
        decisions: {
            accepted: { label: 'Accept as work', done: 'accepted as work', effect: () => 'the time stays counted as work.' },
            rejected: { label: 'Reject', done: 'rejected', effect: () => 'voids the kept entry, so the time no longer counts.' },
        },
    },
    activity: {
        label: 'Unusual activity',
        icon: Activity,
        impact: 'automatic invoices held',
        noPayChange: true,
        decisions: {
            no_concern: { label: 'No concern', done: 'no concern', effect: () => 'closes the review with nothing noted.' },
            follow_up: { label: 'Follow up', done: 'marked to follow up', effect: () => 'marked for follow-up, on record only.' },
        },
        notes: ['Either choice releases the hold on automatic invoices for this period. Pay never changes.'],
    },
    auto_stopped: {
        label: 'Timer stopped automatically',
        icon: TimerOff,
        impact: 'recorded',
        decisions: {
            no_concern: { label: 'Hours look right', done: 'hours kept as recorded', effect: () => 'the hours stay as recorded.' },
            follow_up: { label: 'Follow up', done: 'marked to follow up', effect: () => RECORD_ONLY },
        },
    },
};

const notesFor = (review: Review) => [
    ...(REVIEW_KINDS[review.kind].notes ?? []),
    ...(['lunch_excess', 'idle_kept'].includes(review.kind) ? [FINAL] : []),
];

const decisionFor = (review: Review, decision: string): Decision =>
    REVIEW_KINDS[review.kind].decisions[decision] ?? { label: decision, done: decision, effect: () => '' };

const STOP_REASONS: Record<string, string> = { shift_ended: 'Stopped after the shift ended', max_duration: 'Stopped after running too long' };

function evidenceFor(review: Review): string | null {
    const evidence = review.evidence;
    const entry = entryFor(review.entry);

    if (review.kind === 'activity' && evidence.window_start && evidence.window_end) {
        return `Between ${formatTimeOfDay(String(evidence.window_start), props.timezone)} and ${formatTimeOfDay(String(evidence.window_end), props.timezone)}, input came about every ${evidence.mean_gap_seconds}s, ${evidence.resets} times, almost perfectly evenly. ${evidence.confidence === 'high' ? 'A strong pattern' : 'A possible pattern'}: worth a look, not proof of a problem.`;
    }

    if (review.kind === 'lunch_excess' && evidence.lunch_seconds && evidence.allowance_seconds) {
        return `Lunch took ${formatDuration(Number(evidence.lunch_seconds))} against a ${formatShort(Number(evidence.allowance_seconds))} allowance${entry ? ` · ${when(entry)}` : ''}.`;
    }

    if (review.kind === 'auto_stopped') {
        const reason = STOP_REASONS[String(evidence.reason)] ?? 'Stopped automatically';

        return entry ? `${reason}. Recorded ${when(entry)}.` : `${reason}.`;
    }

    if (!entry) return null;
    if (review.kind === 'lunch_excess') return `Lunch ${when(entry)}.`;

    return `Kept as work: ${when(entry)}.`;
}

const noting = ref<string | null>(null);
const reviewNote = ref('');
const resolving = ref<{ review: string; decision: string } | null>(null);
const reviewError = ref<{ review: string; message: string } | null>(null);
const reviewAnnouncement = ref('');
const lastDecision = ref<string | null>(null);

function resolveReview(review: Review, decision: string) {
    const publicId = review.public_id;
    resolving.value = { review: publicId, decision };
    reviewError.value = null;
    reviewAnnouncement.value = '';
    lastDecision.value = null;
    router.post(
        workspaceRoute('workspace.time.reviews.resolve', { review: publicId }),
        { decision, note: noting.value === publicId ? reviewNote.value || null : null },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                noting.value = null;
                reviewNote.value = '';
                const left = props.reviews.length;
                lastDecision.value = `${REVIEW_KINDS[review.kind].label}, ${formatPeriod(review.work_date, review.work_date)}: ${decisionFor(review, decision).done}.${left ? ` ${left} left for ${props.viewing?.name}.` : ''}`;
                reviewAnnouncement.value = lastDecision.value;
                nextTick(() => document.querySelector<HTMLElement>('[data-review-focus]')?.focus());
            },
            onError: (errors) => {
                reviewError.value = { review: publicId, message: Object.values(errors)[0] ?? "That decision wasn't saved. Try again." };
            },
            onFinish: () => (resolving.value = null),
        },
    );
}

function toggleNote(publicId: string) {
    noting.value = noting.value === publicId ? null : publicId;
    reviewNote.value = '';

    if (noting.value) nextTick(() => document.getElementById(`review-note-${publicId}`)?.focus());
}

const entryFor = (publicId: string | null) => (publicId ? props.entries.find((entry) => entry.public_id === publicId) : undefined);
const entriesInReview = computed(() => new Set(props.reviews.map((review) => review.entry).filter(Boolean)));
const highlightedEntry = ref<string | null>(null);

function viewEntry(publicId: string) {
    const row = document.getElementById(`entry-${publicId}`);

    if (!row) return;

    row.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    row.focus({ preventScroll: true });
    highlightedEntry.value = publicId;
    setTimeout(() => highlightedEntry.value === publicId && (highlightedEntry.value = null), 2500);
}

const teamColumns = computed(() => ({
    lunch: props.team.some((row) => row.lunch_paid > 0),
    leave: props.team.some((row) => row.paid_leave > 0),
    holiday: props.team.some((row) => row.holiday > 0),
    target: props.team.some((row) => row.target !== null),
}));

const needingReview = computed(() => props.team.filter((row) => row.open_reviews > 0).sort((a, b) => b.open_reviews - a.open_reviews));
const openReviewTotal = computed(() => needingReview.value.reduce((sum, row) => sum + row.open_reviews, 0));
const personRoute = (publicId: string) => workspaceRoute('workspace.time.index', { person: publicId });

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
    router.get(personRoute((event.target as HTMLSelectElement).value));
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
        return `${formatPeriod(entry.period_start, entry.period_start)} · ${formatTimeOfDay(entry.started_at, entry.timezone ?? props.timezone)} – ${formatTimeOfDay(entry.ended_at, entry.timezone ?? props.timezone)}${entry.timezone && entry.timezone !== props.timezone ? ` ${zoneLabel(entry.timezone)}` : ''}`;
    }

    return formatPeriod(entry.period_start, entry.period_end);
}

function origin(entry: TimeEntryData): string {
    if (entry.source === 'tracked') return 'Tracked';
    if (entry.source === 'lunch' && entry.adjusts_entry_public_id) return 'Lunch past the allowance, counted by the owner';
    if (
        entry.source === 'lunch' &&
        entry.paid_seconds != null &&
        entry.paid_seconds < entry.duration_seconds &&
        !entriesInReview.value.has(entry.public_id)
    ) {
        const excess = formatShort(entry.duration_seconds - entry.paid_seconds);
        const counted = props.entries.some((other) => other.adjusts_entry_public_id === entry.public_id && !other.voided);

        return `Lunch · ${formatShort(entry.paid_seconds)} paid · ${excess} past the allowance ${counted ? 'counted by the owner' : 'not counted'}`;
    }
    if (entry.source === 'lunch') return 'Paid lunch · counts as paid and billable up to the allowance';
    if (entry.source === 'idle_kept') return 'Idle time kept as work';
    if (entry.adjusts_entry_public_id) return `Correction${entry.created_by ? ` by ${entry.created_by}` : ''}`;
    if (entry.source === 'adjustment') return `Previous hours${entry.created_by ? ` added by ${entry.created_by}` : ''}`;

    return `Added manually${entry.created_by ? ` by ${entry.created_by}` : ''}`;
}

/* "Today · Oct 9 · Asia/Karachi (UTC+5)", or "Oct 8 workday · 6:00 PM–3:00 AM · …" during an overnight shift. */
const workdayLabel = computed(() => (props.workday.is_today ? 'Today' : `${formatBusinessDate(props.workday.date)} workday`));
const workdayContext = computed(() => {
    const shift = props.workday.overnight_shift;
    const parts = [props.workday.is_today ? `Today · ${formatBusinessDate(props.workday.date)}` : workdayLabel.value];
    if (shift && !props.workday.is_today) parts.push(`${formatClockTime(shift.start)}–${formatClockTime(shift.end)}`);
    parts.push(props.workday.timezone_label);
    return parts.join(' · ');
});
</script>

<template>
    <Head title="Time" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <template v-if="scope === 'team'">
                <AppPageHeader title="Time" description="Your team's week. Open a person to see their time and decide anything waiting on you.">
                    <template #meta>
                        <p class="text-muted-foreground text-xs" data-testid="workday-context">{{ workdayContext }}</p>
                        <WorkingClock :timezone="timezone" caption="Workspace time" />
                    </template>
                    <template #actions>
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
                    </template>
                </AppPageHeader>

                <div
                    v-if="openReviewTotal"
                    class="flex flex-col gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 sm:flex-row sm:items-center"
                    data-testid="attention"
                >
                    <p class="flex-1 text-sm">
                        <span class="font-semibold"
                            >{{ openReviewTotal }} {{ openReviewTotal === 1 ? 'item needs' : 'items need' }} your decision</span
                        >
                        <span class="text-muted-foreground">
                            · {{ needingReview.length }} {{ needingReview.length === 1 ? 'person' : 'people' }}</span
                        >
                    </p>
                    <Button size="sm" class="gap-1.5 self-start sm:self-auto" as-child>
                        <Link :href="personRoute(needingReview[0].public_id)" data-testid="start-reviewing">
                            Start with {{ needingReview[0].name }}
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
                <p v-else class="text-muted-foreground flex items-center gap-2 text-sm" data-testid="attention-clear">
                    <CircleCheck class="size-4 text-emerald-600" />
                    Nothing is waiting for your decision.
                </p>

                <div v-if="me" class="bg-card flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl border px-4 py-3" data-testid="my-timer">
                    <div class="flex min-w-0 flex-1 items-center gap-2 text-sm">
                        <template v-if="timer">
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="timer.state === 'running' ? 'animate-pulse bg-emerald-500' : 'bg-amber-500'"
                            />
                            <span class="shrink-0 font-medium">{{
                                timer.state === 'lunch' ? 'You are on lunch' : timer.state === 'running' ? 'Your timer' : 'Your timer is paused'
                            }}</span>
                            <span class="text-muted-foreground truncate">· {{ timer.project.name }}</span>
                            <span class="ml-auto shrink-0 font-semibold tabular-nums">{{
                                formatClock(timer.state === 'lunch' ? lunchElapsed : elapsed)
                            }}</span>
                        </template>
                        <span v-else class="text-muted-foreground">Your timer isn't running.</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template v-if="timer">
                            <Button v-if="timer.state === 'lunch'" size="sm" :disabled="busy" @click="timerAction('lunch-end')">End lunch</Button>
                            <Button
                                v-else-if="timer.state === 'running'"
                                size="sm"
                                variant="outline"
                                class="gap-1.5"
                                :disabled="busy"
                                @click="timerAction('pause')"
                            >
                                <Pause class="size-4" />
                                Pause
                            </Button>
                            <Button v-else size="sm" variant="outline" class="gap-1.5" :disabled="busy" @click="timerAction('resume')">
                                <Play class="size-4" />
                                Resume
                            </Button>
                            <Button v-if="timer.state !== 'lunch'" size="sm" class="gap-1.5" :disabled="busy" @click="timerAction('stop')">
                                <Square class="size-4" />
                                Stop
                            </Button>
                        </template>
                        <Button size="sm" variant="ghost" class="gap-1.5" as-child>
                            <Link :href="personRoute(me.public_id)" data-testid="my-time-link">
                                Your time
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                    </div>
                </div>
                <div v-else class="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm" data-testid="no-profile">
                    Your account isn't linked to a Team profile yet, so you can't track your own time. Link your account in Team.
                </div>

                <section class="bg-card overflow-hidden rounded-2xl border" data-testid="team">
                    <div class="border-b px-4 py-3">
                        <h2 class="text-sm font-semibold">This week</h2>
                    </div>
                    <AppEmptyState
                        v-if="!team.length"
                        compact
                        title="No one on the team yet"
                        description="Add people in Team to see their time here."
                    >
                        <template #icon><Clock class="size-5" /></template>
                    </AppEmptyState>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="text-muted-foreground text-xs">
                                <tr class="border-b">
                                    <th class="px-4 py-2 text-left font-medium">Person</th>
                                    <th class="px-2 py-2 text-left font-medium">Needs you</th>
                                    <th class="px-2 py-2 text-right font-medium">Worked</th>
                                    <th v-if="teamColumns.lunch" class="px-2 py-2 text-right font-medium">Paid lunch</th>
                                    <th v-if="teamColumns.leave" class="px-2 py-2 text-right font-medium">Leave</th>
                                    <th v-if="teamColumns.holiday" class="px-2 py-2 text-right font-medium">Holiday</th>
                                    <th class="px-4 py-2 text-right font-medium">Paid time</th>
                                    <th v-if="teamColumns.target" class="px-4 py-2 text-right font-medium">Over target</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y whitespace-nowrap tabular-nums">
                                <tr v-for="row in team" :key="row.public_id" class="hover:bg-muted/40">
                                    <td class="px-4 py-2.5">
                                        <Link :href="personRoute(row.public_id)" class="flex items-center gap-2.5 font-medium hover:underline">
                                            <AppAvatar :name="row.name" size="xs" />
                                            {{ row.name
                                            }}<span v-if="row.public_id === me?.public_id" class="text-muted-foreground font-normal"> (you)</span>
                                        </Link>
                                    </td>
                                    <td class="px-2 py-2.5" :data-testid="`open-reviews-${row.public_id}`">
                                        <Link
                                            v-if="row.open_reviews"
                                            :href="personRoute(row.public_id)"
                                            :aria-label="`${row.open_reviews} to review for ${row.name}`"
                                        >
                                            <AppBadge variant="warning" dot>{{ row.open_reviews }} to review</AppBadge>
                                        </Link>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">{{ formatDuration(row.worked) }}</td>
                                    <td v-if="teamColumns.lunch" class="px-2 py-2.5 text-right">{{ formatDuration(row.lunch_paid) }}</td>
                                    <td v-if="teamColumns.leave" class="px-2 py-2.5 text-right">{{ formatDuration(row.paid_leave) }}</td>
                                    <td v-if="teamColumns.holiday" class="px-2 py-2.5 text-right">{{ formatDuration(row.holiday) }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium">{{ formatDuration(row.compensated) }}</td>
                                    <td
                                        v-if="teamColumns.target"
                                        class="px-4 py-2.5 text-right"
                                        :class="
                                            row.over_target > 0 &&
                                            policy.overtime_mode === 'review' &&
                                            'font-semibold text-amber-700 dark:text-amber-400'
                                        "
                                    >
                                        {{ row.target === null ? '—' : formatDuration(row.over_target) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <p v-if="policyNotice" class="text-muted-foreground max-w-3xl text-xs" data-testid="policy-notice">{{ policyNotice }}</p>
            </template>

            <template v-else>
                <div v-if="canManageTeam" class="flex flex-col gap-4">
                    <Link
                        :href="workspaceRoute('workspace.time.index')"
                        class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 self-start text-sm"
                        data-testid="back-to-team"
                    >
                        <ArrowLeft class="size-4" />
                        Team
                    </Link>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between" data-testid="person-header">
                        <div class="flex min-w-0 items-center gap-3">
                            <AppAvatar v-if="viewing" :name="viewing.name" size="lg" />
                            <div class="min-w-0">
                                <h1 class="truncate text-2xl font-semibold tracking-tight sm:text-[28px]">
                                    {{ isSelf ? 'Your time' : (viewing?.name ?? 'Time') }}
                                </h1>
                                <p v-if="viewing && !isSelf" class="text-muted-foreground flex items-center gap-2 text-sm" data-testid="other-timer">
                                    <template v-if="timer">
                                        <span
                                            class="size-2 rounded-full"
                                            :class="timer.state === 'running' ? 'animate-pulse bg-emerald-500' : 'bg-amber-500'"
                                        />
                                        {{ timer.state === 'running' ? 'Running' : timer.state === 'lunch' ? 'On lunch' : 'Paused' }} on
                                        {{ timer.project.name }} ·
                                        <span class="tabular-nums">{{ formatClock(timer.state === 'lunch' ? lunchElapsed : elapsed) }}</span>
                                    </template>
                                    <template v-else>No timer running</template>
                                </p>
                                <p class="text-muted-foreground text-xs" data-testid="workday-context">{{ workdayContext }}</p>
                            </div>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Switch to</span>
                            <select
                                class="bg-background h-9 rounded-md border px-2 text-sm"
                                :value="viewing?.public_id ?? ''"
                                data-testid="person-select"
                                @change="viewPerson"
                            >
                                <option v-for="person in people" :key="person.public_id" :value="person.public_id">
                                    {{ person.public_id === me?.public_id ? `You (${person.name})` : person.name }}
                                </option>
                            </select>
                        </label>
                    </div>
                </div>

                <template v-else>
                    <AppPageHeader title="Time" description="Track time on your projects, or add time you worked without a timer.">
                        <template #meta>
                            <p class="text-muted-foreground text-xs" data-testid="workday-context">{{ workdayContext }}</p>
                            <WorkingClock :timezone="timezone" caption="Workspace time" />
                        </template>
                    </AppPageHeader>
                </template>

                <div v-if="!viewing" class="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm" data-testid="no-profile">
                    Your account isn't linked to a Team profile yet, so there's no time to show for you. Ask the workspace owner to link your account
                    to your Team profile.
                </div>

                <template v-else>
                    <!-- Timer -->
                    <section v-if="isSelf" class="bg-card rounded-3xl border p-5 sm:p-6" data-testid="timer">
                        <template v-if="timer">
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
                                <p v-if="lunchOver" class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                    Past the paid lunch allowance. The extra only counts if the owner decides it should.
                                </p>
                                <Button class="mt-5 gap-1.5" :disabled="busy" data-testid="end-lunch" @click="timerAction('lunch-end')"
                                    >End lunch</Button
                                >
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
                                            Started {{ formatTimeOfDay(timer.started_at, timer.timezone ?? timezone)
                                            }}<template v-if="timer.timezone && timer.timezone !== timezone">
                                                {{ zoneLabel(timer.timezone) }}</template
                                            ><template v-if="!timer.billable"> · Non-billable</template>
                                        </p>
                                    </div>
                                    <p class="text-4xl font-semibold tracking-tight tabular-nums" data-testid="timer-clock">
                                        {{ formatClock(elapsed) }}
                                    </p>
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

                        <div v-else-if="policy.idle_enabled" class="flex flex-col gap-4 sm:flex-row sm:items-center" data-testid="desktop-required">
                            <span class="bg-muted flex size-11 shrink-0 items-center justify-center rounded-xl"><Monitor class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">SprintSync Desktop required</p>
                                <p class="text-muted-foreground text-sm">
                                    This workspace pauses timers after inactivity, which only the desktop app can detect. Start your timer from
                                    SprintSync Desktop. You can still add time you worked below.
                                </p>
                            </div>
                            <Button as="a" :href="`${route('home')}#desktop`" class="shrink-0 gap-1.5" data-testid="get-desktop">
                                <Monitor class="size-4" />
                                Get SprintSync Desktop
                            </Button>
                        </div>

                        <form v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="start">
                            <div class="grid gap-1.5">
                                <Label for="timer-project">Project</Label>
                                <select
                                    id="timer-project"
                                    v-model="startForm.project"
                                    class="bg-background h-10 rounded-md border px-3 text-sm"
                                    data-testid="timer-project"
                                >
                                    <option v-for="project in projects" :key="project.public_id" :value="project.public_id">
                                        {{ project.name }}
                                    </option>
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

                    <p class="sr-only" aria-live="polite">{{ reviewAnnouncement }}</p>
                    <p
                        v-if="canManageTeam && lastDecision"
                        class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400"
                        data-testid="decision-confirmation"
                    >
                        <CircleCheck class="size-4 shrink-0" />
                        {{ lastDecision }}
                    </p>
                    <section v-if="canManageTeam && reviews.length" class="bg-card rounded-2xl border border-amber-500/30" data-testid="reviews">
                        <div class="flex items-center gap-2 border-b px-4 py-3">
                            <h2 class="text-sm font-semibold outline-none" tabindex="-1" data-review-focus>Waiting on your decision</h2>
                            <AppBadge variant="warning">{{ reviews.length }}</AppBadge>
                        </div>
                        <ul class="divide-y">
                            <li v-for="review in reviews" :key="review.public_id" class="grid gap-3 px-4 py-4 sm:px-5" :data-kind="review.kind">
                                <div class="flex gap-3">
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-400"
                                    >
                                        <component :is="REVIEW_KINDS[review.kind].icon" class="size-4" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-sm font-semibold">
                                            {{ REVIEW_KINDS[review.kind].label }}
                                            <span class="text-muted-foreground font-normal"
                                                >· {{ formatPeriod(review.work_date, review.work_date) }}</span
                                            >
                                        </h3>
                                        <p v-if="evidenceFor(review)" class="mt-0.5 text-sm" data-testid="review-evidence">
                                            {{ evidenceFor(review) }}
                                        </p>
                                        <p v-if="!evidenceFor(review) || review.kind === 'idle_kept'" class="text-muted-foreground mt-0.5 text-sm">
                                            {{ review.summary }}
                                        </p>
                                    </div>
                                    <div
                                        v-if="review.seconds || REVIEW_KINDS[review.kind].noPayChange"
                                        class="max-w-28 shrink-0 text-right sm:max-w-none"
                                        data-testid="review-impact"
                                    >
                                        <p v-if="REVIEW_KINDS[review.kind].noPayChange" class="text-sm leading-tight font-semibold">No pay change</p>
                                        <p v-else class="text-lg leading-tight font-semibold tabular-nums">{{ formatShort(review.seconds ?? 0) }}</p>
                                        <p class="text-muted-foreground text-xs">{{ REVIEW_KINDS[review.kind].impact }}</p>
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:pl-12">
                                    <div v-if="noting === review.public_id" class="grid gap-1.5">
                                        <Label :for="`review-note-${review.public_id}`">
                                            Note <span class="text-muted-foreground font-normal">(optional, saved with your decision)</span>
                                        </Label>
                                        <input
                                            :id="`review-note-${review.public_id}`"
                                            v-model="reviewNote"
                                            maxlength="500"
                                            class="bg-background h-9 rounded-md border px-3 text-sm"
                                            placeholder="e.g. Agreed with the team lead"
                                            data-testid="review-note"
                                        />
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                        <div class="flex flex-wrap gap-2" role="group" :aria-label="`Decide: ${REVIEW_KINDS[review.kind].label}`">
                                            <Button
                                                v-for="decision in review.decisions"
                                                :key="decision"
                                                variant="outline"
                                                class="gap-1.5"
                                                :disabled="resolving !== null"
                                                :aria-label="`${decisionFor(review, decision).label}: ${viewing.name}, ${formatPeriod(review.work_date, review.work_date)}`"
                                                :aria-describedby="
                                                    [
                                                        `review-effect-${review.public_id}-${decision}`,
                                                        ...notesFor(review).map((_, index) => `review-hint-${review.public_id}-${index}`),
                                                    ].join(' ')
                                                "
                                                :data-testid="`review-${decision}`"
                                                @click="resolveReview(review, decision)"
                                            >
                                                <Loader2
                                                    v-if="resolving?.review === review.public_id && resolving.decision === decision"
                                                    class="size-4 animate-spin"
                                                />
                                                {{ decisionFor(review, decision).label }}
                                            </Button>
                                        </div>
                                        <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 sm:w-auto">
                                            <button
                                                v-if="
                                                    review.kind === 'auto_stopped' &&
                                                    entryFor(review.entry) &&
                                                    entryActions(entryFor(review.entry)!).length
                                                "
                                                type="button"
                                                class="text-muted-foreground hover:text-foreground inline-flex min-h-7 items-center gap-1.5 text-sm underline-offset-4 hover:underline"
                                                data-testid="review-correct"
                                                @click="openCorrect(entryFor(review.entry)!)"
                                            >
                                                <Clock class="size-3.5" />
                                                Correct hours…
                                            </button>
                                            <button
                                                v-if="entryFor(review.entry)"
                                                type="button"
                                                class="text-muted-foreground hover:text-foreground inline-flex min-h-7 items-center gap-1.5 text-sm underline-offset-4 hover:underline"
                                                data-testid="review-view-entry"
                                                @click="viewEntry(review.entry!)"
                                            >
                                                <Eye class="size-3.5" />
                                                View entry
                                            </button>
                                            <button
                                                type="button"
                                                class="text-muted-foreground hover:text-foreground inline-flex min-h-7 items-center gap-1.5 text-sm underline-offset-4 hover:underline"
                                                :aria-expanded="noting === review.public_id"
                                                :aria-controls="`review-note-${review.public_id}`"
                                                data-testid="review-note-toggle"
                                                @click="toggleNote(review.public_id)"
                                            >
                                                <MessageSquarePlus class="size-3.5" />
                                                {{ noting === review.public_id ? 'Remove note' : 'Add a note' }}
                                            </button>
                                        </div>
                                    </div>
                                    <InputError v-if="reviewError?.review === review.public_id" role="alert" :message="reviewError.message" />
                                    <ul class="text-muted-foreground grid gap-0.5 text-xs" data-testid="review-effects">
                                        <li
                                            v-for="decision in review.decisions"
                                            :id="`review-effect-${review.public_id}-${decision}`"
                                            :key="decision"
                                        >
                                            <span class="text-foreground font-medium">{{ decisionFor(review, decision).label }}</span
                                            >:
                                            {{ decisionFor(review, decision).effect(review) }}
                                        </li>
                                        <li v-for="(hint, index) in notesFor(review)" :id="`review-hint-${review.public_id}-${index}`" :key="hint">
                                            {{ hint }}
                                        </li>
                                    </ul>
                                </div>
                            </li>
                        </ul>
                    </section>
                    <div
                        v-else-if="canManageTeam"
                        class="flex flex-col gap-3 rounded-2xl border px-4 py-3 outline-none sm:flex-row sm:items-center"
                        tabindex="-1"
                        data-review-focus
                        data-testid="reviews-clear"
                    >
                        <p class="flex flex-1 items-center gap-2 text-sm">
                            <CircleCheck class="size-4 shrink-0 text-emerald-600" />
                            {{ isSelf ? 'Nothing of yours is waiting for a decision.' : `Nothing is waiting on your decision for ${viewing.name}.` }}
                        </p>
                        <Button v-if="nextNeedingReview" size="sm" class="gap-1.5 self-start sm:self-auto" as-child>
                            <Link :href="personRoute(nextNeedingReview.public_id)" data-testid="next-needing-review">
                                Next: {{ nextNeedingReview.name }} ({{ nextNeedingReview.open_reviews }})
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button v-else size="sm" variant="ghost" class="gap-1.5 self-start sm:self-auto" as-child>
                            <Link :href="workspaceRoute('workspace.time.index')">
                                <ArrowLeft class="size-4" />
                                Back to Team
                            </Link>
                        </Button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3" data-testid="totals">
                        <div class="bg-card rounded-2xl border p-4 text-sm" data-testid="total-today">
                            <p class="text-muted-foreground">{{ workdayLabel }}</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ formatDuration(totals.today) }}</p>
                            <p class="text-muted-foreground text-xs">worked</p>
                            <dl v-if="breakdown" class="mt-3 space-y-1 border-t pt-3 tabular-nums">
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
                                <div class="flex justify-between font-medium">
                                    <dt>Paid time</dt>
                                    <dd>{{ formatDuration(breakdown.today.compensated) }}</dd>
                                </div>
                                <div class="flex justify-between" data-testid="billable-today">
                                    <dt class="text-muted-foreground">Billable</dt>
                                    <dd>{{ formatDuration(breakdown.today.billable) }}</dd>
                                </div>
                            </dl>
                        </div>
                        <div class="bg-card rounded-2xl border p-4 text-sm" data-testid="total-week">
                            <p class="text-muted-foreground">This week</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ formatDuration(totals.week) }}</p>
                            <p class="text-muted-foreground text-xs">
                                worked<template v-if="breakdown?.week.target != null"> · target {{ formatDuration(breakdown.week.target) }}</template>
                            </p>
                            <dl v-if="breakdown" class="mt-3 space-y-1 border-t pt-3 tabular-nums">
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
                                <div class="flex justify-between font-medium">
                                    <dt>Paid time</dt>
                                    <dd>{{ formatDuration(breakdown.week.compensated) }}</dd>
                                </div>
                                <div class="flex justify-between" data-testid="billable-week">
                                    <dt class="text-muted-foreground">Billable</dt>
                                    <dd>{{ formatDuration(breakdown.week.billable) }}</dd>
                                </div>
                                <div v-if="breakdown.week.target !== null" class="flex justify-between" data-testid="over-target">
                                    <dt class="text-muted-foreground">Over target</dt>
                                    <dd>{{ formatDuration(breakdown.week.over_target) }}</dd>
                                </div>
                                <div v-if="breakdown.week.outside_schedule" class="flex justify-between">
                                    <dt class="text-muted-foreground">Outside scheduled hours</dt>
                                    <dd>{{ formatDuration(breakdown.week.outside_schedule) }}</dd>
                                </div>
                                <div v-if="breakdown.week.lunch_excess_open" class="flex justify-between text-amber-700 dark:text-amber-400">
                                    <dt>Long lunch, waiting for review</dt>
                                    <dd>{{ formatDuration(breakdown.week.lunch_excess_open) }}</dd>
                                </div>
                            </dl>
                        </div>
                        <div class="bg-card rounded-2xl border p-4 text-sm" data-testid="total-month">
                            <p class="text-muted-foreground">This month</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ formatDuration(totals.month) }}</p>
                            <p class="text-muted-foreground text-xs">worked</p>
                        </div>
                    </div>

                    <!-- Idle time: keep as work (with a reason) or drop -->
                    <section
                        v-if="idlePeriods.some((period) => period.decision === 'pending')"
                        class="bg-card rounded-2xl border"
                        data-testid="idle-periods"
                    >
                        <div class="border-b px-4 py-3">
                            <h2 class="text-sm font-semibold">Idle time</h2>
                            <p v-if="!isSelf" class="text-muted-foreground text-xs">Waiting for {{ viewing.name }} to keep it as work or drop it.</p>
                        </div>
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
                                        ><template v-if="period.ended_at"> · {{ formatDuration(period.seconds) }}</template> without activity.
                                        {{ isSelf ? 'Not counted as work unless you keep it.' : 'Not counted as work unless they keep it.' }}</span
                                    >
                                </p>
                                <form
                                    v-if="isSelf && keeping === period.public_id"
                                    class="flex flex-col gap-2 sm:flex-row"
                                    @submit.prevent="keepIdle(period.public_id)"
                                >
                                    <input
                                        v-model="keepForm.reason"
                                        class="bg-background h-9 flex-1 rounded-md border px-2"
                                        aria-label="What were you doing?"
                                        placeholder="What was it? e.g. Client phone call"
                                        data-testid="idle-reason"
                                    />
                                    <Button type="submit" size="sm" :disabled="keepForm.processing">Keep as work</Button>
                                </form>
                                <InputError v-if="keeping === period.public_id" :message="keepForm.errors.reason" />
                                <div v-if="isSelf && keeping !== period.public_id" class="flex gap-2">
                                    <Button size="sm" variant="outline" data-testid="keep-idle" @click="keeping = period.public_id"
                                        >Keep as work…</Button
                                    >
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
                            <h2 class="text-sm font-semibold">Time entries</h2>
                            <Button variant="outline" size="sm" class="gap-1.5" data-testid="add-time" @click="openAdd">
                                <Plus class="size-4" />
                                Add time
                            </Button>
                        </div>
                        <div class="bg-card overflow-hidden rounded-2xl border">
                            <AppEmptyState
                                v-if="!entries.length"
                                title="No time yet"
                                :description="
                                    isSelf ? 'Start a timer, or add time you worked without one.' : `${viewing.name} hasn't recorded any time yet.`
                                "
                            >
                                <template #icon><Clock class="size-5" /></template>
                            </AppEmptyState>
                            <ul v-else class="divide-y" data-testid="entries">
                                <li
                                    v-for="entry in entries"
                                    :id="`entry-${entry.public_id}`"
                                    :key="entry.public_id"
                                    tabindex="-1"
                                    class="flex items-start gap-3 px-4 py-3.5 outline-none sm:px-5"
                                    :class="[
                                        entry.voided && 'opacity-60',
                                        entriesInReview.has(entry.public_id) && 'bg-amber-500/5',
                                        highlightedEntry === entry.public_id && 'ring-2 ring-amber-500/70 ring-inset',
                                    ]"
                                    :data-source="entry.source"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p class="flex min-w-0 items-center gap-2 font-medium" :class="entry.voided && 'line-through'">
                                            <span class="truncate"
                                                >{{ entry.project_name
                                                }}<span v-if="entry.task_title" class="text-muted-foreground font-normal">
                                                    · {{ entry.task_title }}</span
                                                ></span
                                            >
                                            <AppBadge v-if="entriesInReview.has(entry.public_id)" variant="warning" class="shrink-0"
                                                >In review</AppBadge
                                            >
                                        </p>
                                        <p class="text-muted-foreground text-sm">{{ when(entry) }}</p>
                                        <p class="text-muted-foreground mt-0.5 text-xs">
                                            {{ origin(entry) }}<template v-if="!entry.billable"> · Non-billable</template
                                            ><template v-if="entry.note"> · {{ entry.note }}</template
                                            ><template v-if="entry.stop_reason_label"> · {{ entry.stop_reason_label }}</template
                                            ><template v-if="entry.voided"> · Voided: {{ entry.void_reason }}</template>
                                        </p>
                                    </div>
                                    <span
                                        class="shrink-0 text-sm font-semibold tabular-nums"
                                        :class="entry.duration_seconds < 0 && 'text-destructive'"
                                    >
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

                    <p v-if="policyNotice" class="text-muted-foreground max-w-3xl text-xs" data-testid="policy-notice">{{ policyNotice }}</p>
                </template>
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
