<script setup lang="ts">
import {
    CalendarClock,
    CalendarHeart,
    ChevronLeft,
    Clock,
    Coffee,
    Globe,
    Loader2,
    Plus,
    ShieldCheck,
    TimerOff,
    Trash2,
    TriangleAlert,
    UserMinus,
} from 'lucide-vue-next';

import type { AppSelectOption } from '@/components/ui/AppSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatClockTime, formatDuration, formatPeriod, requestKey } from '@/lib/time';
import { type BreadcrumbItem } from '@/types';

type Schedule = { days: number[] | null; start_time: string | null; end_time: string | null; timezone: string } | null;

const props = defineProps<{
    policy: {
        idle_enabled: boolean;
        idle_minutes: number;
        weekly_target_hours: string;
        lunch_enabled: boolean;
        lunch_minutes: number;
        overtime_mode: 'track_only' | 'review';
        integrity_enabled: boolean;
        shift_stop_enabled: boolean;
        shift_stop_hours: number;
        max_timer_enabled: boolean;
        max_timer_hours: number;
        effective_from: string | null;
    };
    history: { effective_from: string; by: string | null }[];
    workspaceSchedule: Schedule;
    people: { public_id: string; name: string; schedule: Schedule }[];
    holidays: { public_id: string; date: string; name: string; credit_seconds: number; people: string[] }[];
    leaves: {
        public_id: string;
        person: string;
        start_date: string;
        end_date: string;
        type: 'paid' | 'unpaid';
        credit_seconds_per_day: number;
        note: string | null;
    }[];
    timezone: string;
    today: string;
    /** "Asia/Karachi (UTC+5)" */
    workspaceTimezone: string;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Time', href: workspaceRoute('workspace.time.index') },
    { title: 'Time settings', href: workspaceRoute('workspace.time.settings.edit') },
];

const DAYS = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

const hourOptions = (choices: number[], current: number): AppSelectOption<number>[] =>
    [...new Set([...choices, current])].sort((a, b) => a - b).map((hours) => ({ value: hours, label: `${hours} ${hours === 1 ? 'hour' : 'hours'}` }));

const idleOptions = computed<AppSelectOption<number>[]>(() =>
    [...new Set([2, 5, 10, 15, 30, policyForm.idle_minutes])]
        .sort((a, b) => a - b)
        .map((minutes) => ({ value: minutes, label: `${minutes} minutes` })),
);
const shiftStopOptions = computed(() => hourOptions([1, 2, 3, 4, 6, 8, 12], policyForm.shift_stop_hours));
const maxTimerOptions = computed(() => hourOptions([8, 10, 12, 16, 24], policyForm.max_timer_hours));

const SECTIONS = [
    { id: 'rules', label: 'Tracking rules' },
    { id: 'schedule', label: 'Work schedule' },
    { id: 'holidays', label: 'Holidays' },
    { id: 'leave', label: 'Leave' },
];

const segmentClass =
    'has-[:focus-visible]:ring-ring/40 inline-flex cursor-pointer items-center rounded-md px-3 py-1.5 text-sm transition-colors has-[:focus-visible]:ring-2';
const segmentState = (selected: boolean) =>
    selected ? 'bg-background text-foreground font-medium shadow-sm ring-1 ring-border/60' : 'text-muted-foreground hover:text-foreground';

/* ---- Policy ---- */

const policyForm = useForm({
    idle_enabled: props.policy.idle_enabled,
    idle_minutes: props.policy.idle_minutes,
    weekly_target_hours: props.policy.weekly_target_hours,
    lunch_enabled: props.policy.lunch_enabled,
    lunch_minutes: props.policy.lunch_minutes,
    overtime_mode: props.policy.overtime_mode,
    integrity_enabled: props.policy.integrity_enabled,
    shift_stop_enabled: props.policy.shift_stop_enabled,
    shift_stop_hours: props.policy.shift_stop_hours,
    max_timer_enabled: props.policy.max_timer_enabled,
    max_timer_hours: props.policy.max_timer_hours,
});

const rulesOn = computed(
    () =>
        [
            policyForm.idle_enabled,
            policyForm.lunch_enabled,
            policyForm.integrity_enabled,
            policyForm.shift_stop_enabled,
            policyForm.max_timer_enabled,
        ].filter(Boolean).length,
);

function savePolicy() {
    policyForm.put(workspaceRoute('workspace.time.settings.policy'), { preserveScroll: true, onSuccess: () => policyForm.defaults() });
}

function discardPolicy() {
    policyForm.reset();
    policyForm.clearErrors();
}

/* ---- Schedule (the workspace's, or one person's) ---- */

const schedulePerson = ref('');
const scheduleForm = useForm({ person: '', days: [] as number[], start_time: '09:00', end_time: '17:00', timezone: props.timezone });

const schedulePeopleOptions = computed<AppSelectOption<string>[]>(() => [
    { value: '', label: 'Everyone', description: 'The default for anyone without their own schedule' },
    ...props.people.map((person) => ({
        value: person.public_id,
        label: person.name,
        avatar: { name: person.name },
        meta: person.schedule?.days?.length ? 'Own schedule' : undefined,
    })),
]);

function loadSchedule() {
    const schedule = schedulePerson.value
        ? props.people.find((person) => person.public_id === schedulePerson.value)?.schedule
        : props.workspaceSchedule;

    scheduleForm.person = schedulePerson.value;
    scheduleForm.days = [...(schedule?.days ?? [])];
    scheduleForm.start_time = schedule?.start_time ?? '09:00';
    scheduleForm.end_time = schedule?.end_time ?? '17:00';
    scheduleForm.timezone = schedule?.timezone ?? props.timezone;
    scheduleForm.defaults();
    scheduleForm.clearErrors();
}

loadSchedule();
watch(schedulePerson, loadSchedule);

const overnight = computed(() => scheduleForm.days.length > 0 && scheduleForm.end_time <= scheduleForm.start_time);

const scheduleSummary = computed(() => {
    const days = [...scheduleForm.days].sort((a, b) => a - b);

    if (!days.length || !scheduleForm.start_time || !scheduleForm.end_time) {
        return null;
    }

    const runs: [number, number][] = [];

    for (const day of days) {
        const last = runs.at(-1);

        if (last && day === last[1] + 1) {
            last[1] = day;
        } else {
            runs.push([day, day]);
        }
    }

    const name = (value: number) => DAYS.find((day) => day.value === value)?.label ?? '';
    const dayText = runs
        .map(([first, last]) =>
            first === last ? name(first) : last === first + 1 ? `${name(first)}, ${name(last)}` : `${name(first)}–${name(last)}`,
        )
        .join(', ');
    const [startHour, startMinute] = scheduleForm.start_time.split(':').map(Number);
    const [endHour, endMinute] = scheduleForm.end_time.split(':').map(Number);
    let minutes = endHour * 60 + endMinute - (startHour * 60 + startMinute);

    if (minutes <= 0) {
        minutes += 24 * 60;
    }

    return `${dayText} · ${formatClockTime(scheduleForm.start_time)}–${formatClockTime(scheduleForm.end_time)} · ${formatDuration(minutes * 60)} a day`;
});

function saveSchedule() {
    scheduleForm.put(workspaceRoute('workspace.time.settings.schedule'), { preserveScroll: true, onSuccess: () => scheduleForm.defaults() });
}

/* ---- Holidays and leave ---- */

const addingHoliday = ref(false);
const holidayForm = useForm({
    kind: 'holiday',
    name: '',
    start_date: props.today,
    hours: '8',
    people: [] as string[],
    idempotency_key: requestKey(),
});
const holidayForEveryone = ref(true);

function addHoliday() {
    if (holidayForEveryone.value) holidayForm.people = [];
    holidayForm.post(workspaceRoute('workspace.time.settings.time-off'), {
        preserveScroll: true,
        onSuccess: () => {
            holidayForm.reset('name');
            holidayForm.idempotency_key = requestKey();
            addingHoliday.value = false;
        },
    });
}

const addingLeave = ref(false);
const leaveForm = useForm({
    kind: 'leave',
    person: props.people[0]?.public_id ?? '',
    type: 'paid',
    start_date: props.today,
    end_date: props.today,
    hours: '8',
    note: '',
    idempotency_key: requestKey(),
});
const leavePeopleOptions = computed<AppSelectOption<string>[]>(() =>
    props.people.map((person) => ({ value: person.public_id, label: person.name, avatar: { name: person.name } })),
);

function addLeave() {
    leaveForm.post(workspaceRoute('workspace.time.settings.time-off'), {
        preserveScroll: true,
        onSuccess: () => {
            leaveForm.reset('note');
            leaveForm.idempotency_key = requestKey();
            addingLeave.value = false;
        },
    });
}

function openForm(which: 'holiday' | 'leave') {
    if (which === 'holiday') {
        addingHoliday.value = true;
        nextTick(() => document.getElementById('holiday-name')?.focus());
    } else {
        addingLeave.value = true;
        nextTick(() => document.getElementById('leave-start')?.focus());
    }
}

const removing = ref<string | null>(null);

function confirmRemove(key: string) {
    removing.value = key;
    nextTick(() => document.getElementById(`confirm-${key}`)?.focus());
}

function remove(kind: 'holidays' | 'leaves', publicId: string) {
    router.delete(workspaceRoute(`workspace.time.settings.${kind}.destroy`, kind === 'holidays' ? { holiday: publicId } : { leave: publicId }), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}

const upcomingHolidays = computed(() => props.holidays.filter((holiday) => holiday.date >= props.today).sort((a, b) => a.date.localeCompare(b.date)));
const pastHolidays = computed(() => props.holidays.filter((holiday) => holiday.date < props.today));
const dateParts = (date: string) => {
    const value = new Date(`${date}T00:00:00`);

    return { month: value.toLocaleDateString(undefined, { month: 'short' }), day: value.getDate() };
};

const formatWhen = (iso: string) => new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <Head title="Time settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24 lg:p-8 lg:pb-24">
            <Link
                :href="workspaceRoute('workspace.time.index')"
                class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" />
                Time
            </Link>
            <AppPageHeader title="Time settings" description="How time is tracked, paid and reviewed in this workspace. Every rule is optional.">
                <template #meta>
                    <p class="text-muted-foreground flex flex-wrap items-center gap-x-1.5 text-xs" data-testid="workspace-timezone">
                        <Globe class="size-3.5" aria-hidden="true" />
                        Workspace time zone: <span class="text-foreground font-medium">{{ workspaceTimezone }}</span>
                        <span>· used for days, schedules, holidays, leave and totals.</span>
                        <Link :href="workspaceRoute('workspace.settings')" class="text-foreground font-medium underline underline-offset-2"
                            >Change</Link
                        >
                    </p>
                </template>
            </AppPageHeader>

            <div class="grid gap-8 lg:grid-cols-[11rem_minmax(0,1fr)]">
                <nav aria-label="Time settings sections" class="hidden lg:block">
                    <ul class="sticky top-6 grid gap-0.5 text-sm">
                        <li v-for="section in SECTIONS" :key="section.id">
                            <a
                                :href="`#${section.id}`"
                                class="text-muted-foreground hover:text-foreground hover:bg-muted flex items-center justify-between rounded-md px-2.5 py-1.5"
                            >
                                {{ section.label }}
                                <span v-if="section.id === 'rules'" class="text-xs tabular-nums">{{ rulesOn }}/5 on</span>
                                <span v-else-if="section.id === 'holidays' && holidays.length" class="text-xs tabular-nums">{{
                                    holidays.length
                                }}</span>
                                <span v-else-if="section.id === 'leave' && leaves.length" class="text-xs tabular-nums">{{ leaves.length }}</span>
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="grid min-w-0 gap-10">
                    <form id="rules" class="scroll-mt-6" data-testid="policy-form" @submit.prevent="savePolicy">
                        <div class="mb-3">
                            <h2 class="text-base font-semibold">Tracking rules</h2>
                            <p class="text-muted-foreground text-sm">
                                Changes apply from now on. Time already recorded keeps the rules it was recorded under.
                            </p>
                        </div>

                        <div class="bg-card divide-y rounded-2xl border">
                            <section class="flex gap-4 p-5" aria-labelledby="rule-idle">
                                <span class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <Clock class="size-4" aria-hidden="true" />
                                </span>
                                <div class="grid min-w-0 flex-1 gap-3">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 id="rule-idle" class="text-sm font-medium">Idle detection</h3>
                                            <p class="text-muted-foreground text-sm">Pause desktop timers when the computer isn't being used.</p>
                                        </div>
                                        <AppSwitch id="idle-enabled" v-model="policyForm.idle_enabled" label="Idle detection" class="mt-0.5" />
                                    </div>
                                    <div v-if="policyForm.idle_enabled" class="grid gap-2">
                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                            Pause after
                                            <AppSelect
                                                v-model="policyForm.idle_minutes"
                                                :options="idleOptions"
                                                size="sm"
                                                class="w-36"
                                                aria-label="Minutes of inactivity before pausing"
                                                :invalid="!!policyForm.errors.idle_minutes"
                                                data-testid="idle-minutes"
                                            />
                                            of inactivity
                                        </div>
                                        <InputError :message="policyForm.errors.idle_minutes" />
                                        <p class="flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                            <TriangleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                                            While this is on, timers can only be started from SprintSync Desktop.
                                        </p>
                                    </div>
                                    <details class="text-xs">
                                        <summary class="text-muted-foreground hover:text-foreground w-fit cursor-pointer select-none">
                                            How it works
                                        </summary>
                                        <p class="text-muted-foreground mt-1.5 max-w-prose">
                                            SprintSync Desktop pauses the timer when the computer hasn't been used for this long. People can keep idle
                                            time as work by giving a reason. A website can't tell whether the computer is in use, which is why web
                                            timers are turned off while this rule is on.
                                        </p>
                                    </details>
                                </div>
                            </section>

                            <section class="flex gap-4 p-5" aria-labelledby="rule-lunch">
                                <span class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <Coffee class="size-4" aria-hidden="true" />
                                </span>
                                <div class="grid min-w-0 flex-1 gap-3">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 id="rule-lunch" class="text-sm font-medium">Paid lunch</h3>
                                            <p class="text-muted-foreground text-sm">Give people a paid lunch break, shown apart from worked time.</p>
                                        </div>
                                        <AppSwitch id="lunch-enabled" v-model="policyForm.lunch_enabled" label="Paid lunch" class="mt-0.5" />
                                    </div>
                                    <div v-if="policyForm.lunch_enabled" class="grid gap-2">
                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                            <label for="lunch-minutes">Paid for up to</label>
                                            <input
                                                id="lunch-minutes"
                                                v-model.number="policyForm.lunch_minutes"
                                                type="number"
                                                min="5"
                                                max="180"
                                                class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-8 w-20 rounded-md border px-2 text-right tabular-nums outline-none focus-visible:ring-[3px]"
                                                :class="policyForm.errors.lunch_minutes && 'border-destructive'"
                                                :aria-invalid="!!policyForm.errors.lunch_minutes || undefined"
                                                data-testid="lunch-minutes"
                                            />
                                            minutes a day
                                        </div>
                                        <InputError :message="policyForm.errors.lunch_minutes" />
                                    </div>
                                    <details class="text-xs">
                                        <summary class="text-muted-foreground hover:text-foreground w-fit cursor-pointer select-none">
                                            How it works
                                        </summary>
                                        <p class="text-muted-foreground mt-1.5 max-w-prose">
                                            Paid lunch counts toward paid and billable hours up to this allowance. A longer lunch counts only up to
                                            the limit, and the extra comes to you to decide on the Time page.
                                        </p>
                                    </details>
                                </div>
                            </section>

                            <section class="flex gap-4 p-5" aria-labelledby="rule-target">
                                <span class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <CalendarClock class="size-4" aria-hidden="true" />
                                </span>
                                <div class="grid min-w-0 flex-1 gap-3">
                                    <div class="min-w-0">
                                        <h3 id="rule-target" class="text-sm font-medium">Weekly target</h3>
                                        <p class="text-muted-foreground text-sm">A target, never a limit. Timers keep running past it.</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <label for="weekly-target">Expect</label>
                                        <input
                                            id="weekly-target"
                                            v-model="policyForm.weekly_target_hours"
                                            inputmode="decimal"
                                            placeholder="None"
                                            class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-8 w-20 rounded-md border px-2 text-right tabular-nums outline-none focus-visible:ring-[3px]"
                                            :class="policyForm.errors.weekly_target_hours && 'border-destructive'"
                                            :aria-invalid="!!policyForm.errors.weekly_target_hours || undefined"
                                            data-testid="weekly-target"
                                        />
                                        hours a week
                                    </div>
                                    <InputError :message="policyForm.errors.weekly_target_hours" />
                                    <div class="grid gap-1.5">
                                        <p id="overtime-label" class="text-muted-foreground text-xs">Time above the target</p>
                                        <div
                                            role="radiogroup"
                                            aria-labelledby="overtime-label"
                                            class="bg-muted/40 inline-flex w-fit gap-0.5 rounded-lg border p-0.5"
                                        >
                                            <label :class="[segmentClass, segmentState(policyForm.overtime_mode === 'track_only')]">
                                                <input v-model="policyForm.overtime_mode" type="radio" value="track_only" class="sr-only" />
                                                Just track it
                                            </label>
                                            <label :class="[segmentClass, segmentState(policyForm.overtime_mode === 'review')]">
                                                <input v-model="policyForm.overtime_mode" type="radio" value="review" class="sr-only" />
                                                Highlight it for me
                                            </label>
                                        </div>
                                        <p class="text-muted-foreground text-xs">Nothing is paid or converted automatically.</p>
                                    </div>
                                </div>
                            </section>

                            <section class="flex gap-4 p-5" aria-labelledby="rule-integrity">
                                <span class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <ShieldCheck class="size-4" aria-hidden="true" />
                                </span>
                                <div class="grid min-w-0 flex-1 gap-3">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <h3 id="rule-integrity" class="text-sm font-medium">Activity integrity</h3>
                                            <p class="text-muted-foreground text-sm">
                                                Flag unusually repetitive activity, such as keep-awake tools, for review.
                                            </p>
                                        </div>
                                        <AppSwitch
                                            id="integrity-enabled"
                                            v-model="policyForm.integrity_enabled"
                                            label="Activity integrity"
                                            class="mt-0.5"
                                        />
                                    </div>
                                    <details class="text-xs">
                                        <summary class="text-muted-foreground hover:text-foreground w-fit cursor-pointer select-none">
                                            What is checked
                                        </summary>
                                        <p class="text-muted-foreground mt-1.5 max-w-prose">
                                            SprintSync Desktop looks only at when the computer's idle clock resets. It never records keys, mouse
                                            positions, apps, websites or screenshots. A flag is a suggestion to look, never an accusation, and your
                                            team is told this is on.
                                        </p>
                                    </details>
                                </div>
                            </section>

                            <section class="flex gap-4 p-5" aria-labelledby="rule-forgotten" data-testid="abandoned-timers">
                                <span class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                                    <TimerOff class="size-4" aria-hidden="true" />
                                </span>
                                <div class="grid min-w-0 flex-1 gap-4">
                                    <div class="min-w-0">
                                        <h3 id="rule-forgotten" class="text-sm font-medium">Forgotten timers</h3>
                                        <p class="text-muted-foreground text-sm">
                                            Stop timers someone forgot. The time is kept and the stop comes to you to review.
                                        </p>
                                    </div>
                                    <div class="grid gap-2">
                                        <div class="flex items-center justify-between gap-4">
                                            <label for="shift-stop-enabled" class="text-sm">After the person's shift ends</label>
                                            <AppSwitch id="shift-stop-enabled" v-model="policyForm.shift_stop_enabled" label="Stop after the shift" />
                                        </div>
                                        <div v-if="policyForm.shift_stop_enabled" class="flex flex-wrap items-center gap-2 text-sm">
                                            Stop
                                            <AppSelect
                                                v-model="policyForm.shift_stop_hours"
                                                :options="shiftStopOptions"
                                                size="sm"
                                                class="w-32"
                                                aria-label="Hours after the shift ends"
                                                :invalid="!!policyForm.errors.shift_stop_hours"
                                                data-testid="shift-stop-hours"
                                            />
                                            after the scheduled end
                                        </div>
                                        <InputError :message="policyForm.errors.shift_stop_hours" />
                                    </div>
                                    <div class="grid gap-2">
                                        <div class="flex items-center justify-between gap-4">
                                            <label for="max-timer-enabled" class="text-sm">When left untouched for too long</label>
                                            <AppSwitch id="max-timer-enabled" v-model="policyForm.max_timer_enabled" label="Longest timer" />
                                        </div>
                                        <div v-if="policyForm.max_timer_enabled" class="flex flex-wrap items-center gap-2 text-sm">
                                            Stop after
                                            <AppSelect
                                                v-model="policyForm.max_timer_hours"
                                                :options="maxTimerOptions"
                                                size="sm"
                                                class="w-32"
                                                aria-label="Hours without a pause, resume or stop"
                                                :invalid="!!policyForm.errors.max_timer_hours"
                                                data-testid="max-timer-hours"
                                            />
                                            without a pause, resume or stop
                                        </div>
                                        <InputError :message="policyForm.errors.max_timer_hours" />
                                    </div>
                                    <details class="text-xs">
                                        <summary class="text-muted-foreground hover:text-foreground w-fit cursor-pointer select-none">
                                            How it works
                                        </summary>
                                        <p class="text-muted-foreground mt-1.5 max-w-prose">
                                            The time is saved up to the moment the timer was stopped, and both the person and you can see why. Pausing
                                            or resuming counts as activity, so real overtime keeps running. People without a schedule are only covered
                                            by the second rule.
                                        </p>
                                    </details>
                                </div>
                            </section>
                        </div>

                        <div
                            class="mt-3 flex flex-col gap-3 rounded-xl border px-4 py-3 transition-shadow sm:flex-row sm:items-center sm:justify-between"
                            :class="
                                policyForm.isDirty ? 'bg-card sticky bottom-24 z-10 shadow-[0_8px_24px_-12px_rgb(0_0_0/0.25)]' : 'border-transparent'
                            "
                            data-testid="policy-savebar"
                        >
                            <p class="text-muted-foreground text-xs" aria-live="polite">
                                <template v-if="policyForm.isDirty"
                                    ><span class="text-foreground font-medium">You have unsaved changes.</span></template
                                >
                                <template v-else-if="policyForm.recentlySuccessful">Saved. The new rules apply from now on.</template>
                                <template v-else-if="policy.effective_from">Current rules since {{ formatWhen(policy.effective_from) }}.</template>
                                <template v-else>Using the defaults.</template>
                            </p>
                            <div class="flex gap-2">
                                <Button
                                    v-if="policyForm.isDirty"
                                    type="button"
                                    variant="ghost"
                                    :disabled="policyForm.processing"
                                    @click="discardPolicy"
                                >
                                    Discard
                                </Button>
                                <Button
                                    type="submit"
                                    :variant="policyForm.isDirty ? 'default' : 'outline'"
                                    :disabled="policyForm.processing || !policyForm.isDirty"
                                    data-testid="save-policy"
                                >
                                    <Loader2 v-if="policyForm.processing" class="mr-2 size-4 animate-spin" />
                                    Save rules
                                </Button>
                            </div>
                        </div>
                        <details v-if="history.length > 1" class="mt-2 px-1 text-xs">
                            <summary class="text-muted-foreground hover:text-foreground w-fit cursor-pointer select-none">Rule history</summary>
                            <ul class="text-muted-foreground mt-1.5 grid gap-0.5">
                                <li v-for="version in history.slice(1)" :key="version.effective_from">
                                    {{ formatWhen(version.effective_from) }}<template v-if="version.by"> · {{ version.by }}</template>
                                </li>
                            </ul>
                        </details>
                    </form>

                    <form id="schedule" class="scroll-mt-6" data-testid="schedule-form" @submit.prevent="saveSchedule">
                        <div class="mb-3">
                            <h2 class="text-base font-semibold">Work schedule</h2>
                            <p class="text-muted-foreground text-sm">
                                Expected hours, for reporting only. A schedule never creates hours or stops a timer; work outside it is simply shown.
                            </p>
                        </div>
                        <div class="bg-card grid gap-5 rounded-2xl border p-5">
                            <div class="grid gap-1.5 sm:max-w-sm">
                                <Label for="schedule-person">Applies to</Label>
                                <AppSelect
                                    id="schedule-person"
                                    v-model="schedulePerson"
                                    :options="schedulePeopleOptions"
                                    search-placeholder="Search people…"
                                />
                            </div>
                            <fieldset class="grid gap-1.5">
                                <legend class="mb-1.5 text-sm font-medium">Working days</legend>
                                <div class="flex flex-wrap gap-1.5">
                                    <label
                                        v-for="day in DAYS"
                                        :key="day.value"
                                        class="has-[:focus-visible]:ring-ring/40 inline-flex h-9 min-w-12 cursor-pointer items-center justify-center rounded-md border px-3 text-sm transition-colors has-[:focus-visible]:ring-2"
                                        :class="
                                            scheduleForm.days.includes(day.value)
                                                ? 'border-foreground bg-foreground text-background font-medium'
                                                : 'bg-background text-muted-foreground hover:text-foreground hover:border-foreground/30'
                                        "
                                    >
                                        <input v-model="scheduleForm.days" type="checkbox" :value="day.value" class="sr-only" />
                                        {{ day.label }}
                                    </label>
                                </div>
                            </fieldset>
                            <div v-if="scheduleForm.days.length" class="flex flex-wrap items-end gap-3">
                                <div class="grid gap-1.5">
                                    <Label for="schedule-start">Starts</Label>
                                    <input
                                        id="schedule-start"
                                        v-model="scheduleForm.start_time"
                                        type="time"
                                        class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-10 rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                                    />
                                </div>
                                <span class="text-muted-foreground pb-2.5 text-sm">to</span>
                                <div class="grid gap-1.5">
                                    <Label for="schedule-end">Ends</Label>
                                    <input
                                        id="schedule-end"
                                        v-model="scheduleForm.end_time"
                                        type="time"
                                        class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-10 rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                                    />
                                </div>
                            </div>
                            <InputError :message="scheduleForm.errors.end_time || scheduleForm.errors.start_time || scheduleForm.errors.days" />
                            <div class="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm" data-testid="schedule-summary" aria-live="polite">
                                    <template v-if="scheduleSummary">
                                        <span class="font-medium">{{ scheduleSummary }}</span>
                                        <span v-if="overnight" class="text-muted-foreground"> · ends the next day</span>
                                    </template>
                                    <span v-else class="text-muted-foreground">No working days chosen, so there is no schedule.</span>
                                </p>
                                <Button
                                    type="submit"
                                    :variant="scheduleForm.isDirty ? 'default' : 'outline'"
                                    :disabled="scheduleForm.processing || !scheduleForm.isDirty"
                                    class="self-start sm:self-auto"
                                >
                                    <Loader2 v-if="scheduleForm.processing" class="mr-2 size-4 animate-spin" />
                                    {{ scheduleForm.recentlySuccessful && !scheduleForm.isDirty ? 'Saved' : 'Save schedule' }}
                                </Button>
                            </div>
                        </div>
                    </form>

                    <section id="holidays" class="scroll-mt-6" aria-labelledby="holidays-heading">
                        <div class="mb-3 flex items-end justify-between gap-4">
                            <div>
                                <h2 id="holidays-heading" class="text-base font-semibold">Holidays</h2>
                                <p class="text-muted-foreground text-sm">
                                    Paid time off for everyone or a few people. Never tracked or billed to clients.
                                </p>
                            </div>
                            <Button v-if="!addingHoliday" variant="outline" size="sm" class="shrink-0 gap-1.5" @click="openForm('holiday')">
                                <Plus class="size-4" />
                                Add holiday
                            </Button>
                        </div>
                        <div class="bg-card overflow-hidden rounded-2xl border">
                            <form v-if="addingHoliday" class="bg-muted/30 grid gap-4 border-b p-5" @submit.prevent="addHoliday">
                                <div class="grid gap-3 sm:grid-cols-[1fr_10rem_7rem]">
                                    <AppFormInput
                                        id="holiday-name"
                                        v-model="holidayForm.name"
                                        label="Name"
                                        placeholder="Quaid-e-Azam Day"
                                        :error="holidayForm.errors.name"
                                    />
                                    <AppFormInput
                                        id="holiday-date"
                                        v-model="holidayForm.start_date"
                                        type="date"
                                        label="Date"
                                        :error="holidayForm.errors.start_date"
                                    />
                                    <AppFormInput
                                        id="holiday-hours"
                                        v-model="holidayForm.hours"
                                        label="Paid hours"
                                        :error="holidayForm.errors.hours"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <p id="holiday-scope-label" class="text-sm font-medium">Applies to</p>
                                    <div
                                        role="radiogroup"
                                        aria-labelledby="holiday-scope-label"
                                        class="bg-muted/40 inline-flex w-fit gap-0.5 rounded-lg border p-0.5"
                                    >
                                        <label :class="[segmentClass, segmentState(holidayForEveryone)]">
                                            <input v-model="holidayForEveryone" type="radio" :value="true" class="sr-only" />
                                            Everyone
                                        </label>
                                        <label :class="[segmentClass, segmentState(!holidayForEveryone)]">
                                            <input v-model="holidayForEveryone" type="radio" :value="false" class="sr-only" />
                                            Selected people
                                        </label>
                                    </div>
                                    <div v-if="!holidayForEveryone" class="flex max-h-44 flex-wrap gap-1.5 overflow-y-auto">
                                        <label
                                            v-for="person in people"
                                            :key="person.public_id"
                                            class="has-[:focus-visible]:ring-ring/40 inline-flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-sm has-[:focus-visible]:ring-2"
                                            :class="
                                                holidayForm.people.includes(person.public_id)
                                                    ? 'border-foreground bg-muted font-medium'
                                                    : 'bg-background'
                                            "
                                        >
                                            <input v-model="holidayForm.people" type="checkbox" :value="person.public_id" class="sr-only" />
                                            <AppAvatar :name="person.name" size="2xs" />
                                            {{ person.name }}
                                        </label>
                                    </div>
                                    <InputError :message="holidayForm.errors.people" />
                                </div>
                                <div class="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" @click="addingHoliday = false">Cancel</Button>
                                    <Button type="submit" :disabled="holidayForm.processing" data-testid="add-holiday">
                                        <Loader2 v-if="holidayForm.processing" class="mr-2 size-4 animate-spin" />
                                        Add holiday
                                    </Button>
                                </div>
                            </form>

                            <AppEmptyState
                                v-if="!holidays.length"
                                compact
                                title="No holidays yet"
                                description="Add public holidays so everyone's paid time is credited automatically."
                            >
                                <template #icon><CalendarHeart class="size-5" /></template>
                            </AppEmptyState>
                            <ul v-else class="divide-y" data-testid="holidays">
                                <li
                                    v-for="holiday in [...upcomingHolidays, ...pastHolidays]"
                                    :key="holiday.public_id"
                                    class="flex items-center gap-3 px-5 py-3 text-sm"
                                    :class="holiday.date < today && 'text-muted-foreground'"
                                >
                                    <span
                                        class="flex size-10 shrink-0 flex-col items-center justify-center rounded-lg border leading-none"
                                        :class="holiday.date < today ? 'bg-muted/40' : 'bg-background'"
                                        aria-hidden="true"
                                    >
                                        <span class="text-muted-foreground text-[10px] font-medium uppercase">{{
                                            dateParts(holiday.date).month
                                        }}</span>
                                        <span class="mt-0.5 text-sm font-semibold tabular-nums">{{ dateParts(holiday.date).day }}</span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate font-medium" :class="holiday.date >= today && 'text-foreground'">{{ holiday.name }}</p>
                                        <p class="text-muted-foreground truncate text-xs">
                                            {{ formatPeriod(holiday.date, holiday.date) }} · {{ formatDuration(holiday.credit_seconds) }} paid ·
                                            {{ holiday.people.length ? holiday.people.join(', ') : 'Everyone'
                                            }}<template v-if="holiday.date < today"> · Past</template>
                                        </p>
                                    </div>
                                    <div v-if="removing === `holiday-${holiday.public_id}`" class="flex shrink-0 items-center gap-1">
                                        <Button
                                            :id="`confirm-holiday-${holiday.public_id}`"
                                            variant="destructive"
                                            size="sm"
                                            @click="remove('holidays', holiday.public_id)"
                                        >
                                            Remove
                                        </Button>
                                        <Button variant="ghost" size="sm" @click="removing = null">Keep</Button>
                                    </div>
                                    <Button
                                        v-else
                                        variant="ghost"
                                        size="sm"
                                        class="text-muted-foreground hover:text-destructive shrink-0"
                                        :aria-label="`Remove ${holiday.name}`"
                                        @click="confirmRemove(`holiday-${holiday.public_id}`)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section id="leave" class="scroll-mt-6" aria-labelledby="leave-heading">
                        <div class="mb-3 flex items-end justify-between gap-4">
                            <div>
                                <h2 id="leave-heading" class="text-base font-semibold">Leave</h2>
                                <p class="text-muted-foreground text-sm">
                                    Paid leave counts toward paid hours on working days. No leave is billed to clients.
                                </p>
                            </div>
                            <Button
                                v-if="!addingLeave && people.length"
                                variant="outline"
                                size="sm"
                                class="shrink-0 gap-1.5"
                                @click="openForm('leave')"
                            >
                                <Plus class="size-4" />
                                Add leave
                            </Button>
                        </div>
                        <div class="bg-card overflow-hidden rounded-2xl border">
                            <form v-if="addingLeave" class="bg-muted/30 grid gap-4 border-b p-5" @submit.prevent="addLeave">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="grid gap-1.5">
                                        <Label for="leave-person">Person</Label>
                                        <AppSelect
                                            id="leave-person"
                                            v-model="leaveForm.person"
                                            :options="leavePeopleOptions"
                                            search-placeholder="Search people…"
                                            :invalid="!!leaveForm.errors.person"
                                        />
                                        <InputError :message="leaveForm.errors.person" />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <p id="leave-type-label" class="text-sm font-medium">Type</p>
                                        <div
                                            role="radiogroup"
                                            aria-labelledby="leave-type-label"
                                            class="bg-muted/40 inline-flex h-10 w-fit items-center gap-0.5 rounded-lg border p-0.5"
                                        >
                                            <label :class="[segmentClass, segmentState(leaveForm.type === 'paid')]">
                                                <input v-model="leaveForm.type" type="radio" value="paid" class="sr-only" />
                                                Paid
                                            </label>
                                            <label :class="[segmentClass, segmentState(leaveForm.type === 'unpaid')]">
                                                <input v-model="leaveForm.type" type="radio" value="unpaid" class="sr-only" />
                                                Unpaid
                                            </label>
                                        </div>
                                    </div>
                                    <AppFormInput
                                        id="leave-start"
                                        v-model="leaveForm.start_date"
                                        type="date"
                                        label="From"
                                        :error="leaveForm.errors.start_date"
                                    />
                                    <AppFormInput
                                        id="leave-end"
                                        v-model="leaveForm.end_date"
                                        type="date"
                                        label="To"
                                        :error="leaveForm.errors.end_date"
                                    />
                                    <AppFormInput
                                        v-if="leaveForm.type === 'paid'"
                                        id="leave-hours"
                                        v-model="leaveForm.hours"
                                        label="Paid hours per working day"
                                        :error="leaveForm.errors.hours"
                                    />
                                    <AppFormInput
                                        id="leave-note"
                                        v-model="leaveForm.note"
                                        label="Note"
                                        hint="Optional"
                                        :error="leaveForm.errors.note"
                                    />
                                </div>
                                <div class="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" @click="addingLeave = false">Cancel</Button>
                                    <Button type="submit" :disabled="leaveForm.processing" data-testid="add-leave">
                                        <Loader2 v-if="leaveForm.processing" class="mr-2 size-4 animate-spin" />
                                        Add leave
                                    </Button>
                                </div>
                            </form>

                            <AppEmptyState
                                v-if="!leaves.length"
                                compact
                                title="No leave recorded"
                                :description="
                                    people.length
                                        ? 'Record paid or unpaid leave so totals stay accurate.'
                                        : 'Add people in Team before recording leave.'
                                "
                            >
                                <template #icon><UserMinus class="size-5" /></template>
                            </AppEmptyState>
                            <ul v-else class="divide-y" data-testid="leaves">
                                <li v-for="leave in leaves" :key="leave.public_id" class="flex items-center gap-3 px-5 py-3 text-sm">
                                    <AppAvatar :name="leave.person" size="sm" class="shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <p class="flex min-w-0 items-center gap-2">
                                            <span class="truncate font-medium">{{ leave.person }}</span>
                                            <AppBadge :variant="leave.type === 'paid' ? 'success' : 'neutral'" class="shrink-0">
                                                {{ leave.type === 'paid' ? 'Paid' : 'Unpaid' }}
                                            </AppBadge>
                                        </p>
                                        <p class="text-muted-foreground truncate text-xs">
                                            {{ formatPeriod(leave.start_date, leave.end_date)
                                            }}<template v-if="leave.type === 'paid'">
                                                · {{ formatDuration(leave.credit_seconds_per_day) }} a day</template
                                            ><template v-if="leave.note"> · {{ leave.note }}</template>
                                        </p>
                                    </div>
                                    <div v-if="removing === `leave-${leave.public_id}`" class="flex shrink-0 items-center gap-1">
                                        <Button
                                            :id="`confirm-leave-${leave.public_id}`"
                                            variant="destructive"
                                            size="sm"
                                            @click="remove('leaves', leave.public_id)"
                                        >
                                            Remove
                                        </Button>
                                        <Button variant="ghost" size="sm" @click="removing = null">Keep</Button>
                                    </div>
                                    <Button
                                        v-else
                                        variant="ghost"
                                        size="sm"
                                        class="text-muted-foreground hover:text-destructive shrink-0"
                                        :aria-label="`Remove ${leave.person}'s leave`"
                                        @click="confirmRemove(`leave-${leave.public_id}`)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
