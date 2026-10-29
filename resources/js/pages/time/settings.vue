<script setup lang="ts">
import { CalendarHeart, ChevronLeft, Clock, Coffee, Loader2, ShieldCheck, Trash2, UserMinus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration, formatPeriod, requestKey } from '@/lib/time';
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

const IDLE_CHOICES = [2, 5, 10, 15, 30];
const DAYS = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

/* ---- Policy ---- */

const policyForm = useForm({
    idle_enabled: props.policy.idle_enabled,
    idle_minutes: props.policy.idle_minutes,
    weekly_target_hours: props.policy.weekly_target_hours,
    lunch_enabled: props.policy.lunch_enabled,
    lunch_minutes: props.policy.lunch_minutes,
    overtime_mode: props.policy.overtime_mode,
    integrity_enabled: props.policy.integrity_enabled,
});

function savePolicy() {
    policyForm.put(workspaceRoute('workspace.time.settings.policy'), { preserveScroll: true });
}

/* ---- Schedule (the workspace's, or one person's) ---- */

const schedulePerson = ref('');
const scheduleForm = useForm({ person: '', days: [] as number[], start_time: '09:00', end_time: '17:00', timezone: props.timezone });

function loadSchedule() {
    const schedule = schedulePerson.value
        ? props.people.find((person) => person.public_id === schedulePerson.value)?.schedule
        : props.workspaceSchedule;

    scheduleForm.person = schedulePerson.value;
    scheduleForm.days = [...(schedule?.days ?? [])];
    scheduleForm.start_time = schedule?.start_time ?? '09:00';
    scheduleForm.end_time = schedule?.end_time ?? '17:00';
    scheduleForm.timezone = schedule?.timezone ?? props.timezone;
}

loadSchedule();
watch(schedulePerson, loadSchedule);

const overnight = computed(() => scheduleForm.days.length > 0 && scheduleForm.end_time <= scheduleForm.start_time);

function saveSchedule() {
    scheduleForm.put(workspaceRoute('workspace.time.settings.schedule'), { preserveScroll: true });
}

/* ---- Holidays and leave ---- */

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
        },
    });
}

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

function addLeave() {
    leaveForm.post(workspaceRoute('workspace.time.settings.time-off'), {
        preserveScroll: true,
        onSuccess: () => {
            leaveForm.reset('note');
            leaveForm.idempotency_key = requestKey();
        },
    });
}

function remove(kind: 'holidays' | 'leaves', publicId: string) {
    router.delete(workspaceRoute(`workspace.time.settings.${kind}.destroy`, kind === 'holidays' ? { holiday: publicId } : { leave: publicId }), {
        preserveScroll: true,
    });
}

const formatWhen = (iso: string) => new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <Head title="Time settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <Link
                :href="workspaceRoute('workspace.time.index')"
                class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" />
                Time
            </Link>
            <AppPageHeader
                eyebrow="Time"
                title="Time settings"
                description="Everything here is optional. Turned off, time tracking works exactly as before."
            />

            <p class="bg-muted/60 text-muted-foreground rounded-xl px-4 py-3 text-xs" data-testid="workspace-timezone">
                Workspace time zone: <span class="text-foreground font-medium">{{ workspaceTimezone }}</span
                >. Days, schedules, holidays, leave, paid lunch and time totals all use it. A shift that runs past midnight counts on the day it
                started.
                <Link :href="workspaceRoute('workspace.settings')" class="text-foreground font-medium underline underline-offset-2"
                    >Change it in Settings</Link
                >
            </p>

            <!-- Policy -->
            <form class="bg-card space-y-6 rounded-3xl border p-5 sm:p-6" data-testid="policy-form" @submit.prevent="savePolicy">
                <section class="space-y-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold"><Clock class="size-4" /> Idle detection</h2>
                    <label class="flex items-center gap-2 text-sm">
                        <AppSwitch id="idle-enabled" v-model="policyForm.idle_enabled" label="Idle detection" />
                        Pause desktop timers after inactivity
                    </label>
                    <div v-if="policyForm.idle_enabled" class="flex flex-wrap items-center gap-2 text-sm">
                        Pause after
                        <select v-model.number="policyForm.idle_minutes" class="bg-background h-9 rounded-md border px-2" data-testid="idle-minutes">
                            <option v-for="minutes in IDLE_CHOICES" :key="minutes" :value="minutes">{{ minutes }} minutes</option>
                            <option v-if="!IDLE_CHOICES.includes(policyForm.idle_minutes)" :value="policyForm.idle_minutes">
                                {{ policyForm.idle_minutes }} minutes
                            </option>
                        </select>
                        of inactivity
                    </div>
                    <InputError :message="policyForm.errors.idle_minutes" />
                    <p class="text-muted-foreground text-xs">
                        SprintSync Desktop pauses the timer when the computer hasn't been used for this long. People can keep idle time as work with a
                        reason. Web timers can't see whether the computer is in use, so this only applies on desktop.
                    </p>
                </section>

                <section class="space-y-3 border-t pt-5">
                    <h2 class="text-sm font-semibold">Weekly hours</h2>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        Expected
                        <input
                            v-model="policyForm.weekly_target_hours"
                            inputmode="decimal"
                            placeholder="40"
                            class="bg-background h-9 w-20 rounded-md border px-2 text-right"
                            data-testid="weekly-target"
                        />
                        hours a week
                    </div>
                    <InputError :message="policyForm.errors.weekly_target_hours" />
                    <p class="text-muted-foreground text-xs">A target, never a limit: timers keep running and time above it is shown separately.</p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <label
                            class="flex items-center gap-2 rounded-lg border px-3 py-1.5"
                            :class="policyForm.overtime_mode === 'track_only' && 'border-foreground'"
                        >
                            <input v-model="policyForm.overtime_mode" type="radio" value="track_only" /> Track only
                        </label>
                        <label
                            class="flex items-center gap-2 rounded-lg border px-3 py-1.5"
                            :class="policyForm.overtime_mode === 'review' && 'border-foreground'"
                        >
                            <input v-model="policyForm.overtime_mode" type="radio" value="review" /> Highlight for my review
                        </label>
                    </div>
                    <p class="text-muted-foreground text-xs">
                        Paid overtime and time-off credit come later. Nothing is paid or converted automatically.
                    </p>
                </section>

                <section class="space-y-3 border-t pt-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold"><Coffee class="size-4" /> Paid lunch</h2>
                    <label class="flex items-center gap-2 text-sm">
                        <AppSwitch id="lunch-enabled" v-model="policyForm.lunch_enabled" label="Paid lunch" />
                        Offer a paid lunch break
                    </label>
                    <div v-if="policyForm.lunch_enabled" class="flex flex-wrap items-center gap-2 text-sm">
                        Paid for up to
                        <input
                            v-model.number="policyForm.lunch_minutes"
                            type="number"
                            min="5"
                            max="180"
                            class="bg-background h-9 w-20 rounded-md border px-2 text-right"
                            data-testid="lunch-minutes"
                        />
                        minutes a day
                    </div>
                    <InputError :message="policyForm.errors.lunch_minutes" />
                    <p class="text-muted-foreground text-xs">
                        Paid lunch counts toward paid and billable hours up to the configured allowance. It is shown separately from worked time.
                        Longer lunches count only up to this limit; the extra is sent to you to decide.
                    </p>
                </section>

                <section class="space-y-3 border-t pt-5">
                    <h2 class="flex items-center gap-2 text-sm font-semibold"><ShieldCheck class="size-4" /> Activity integrity</h2>
                    <label class="flex items-center gap-2 text-sm">
                        <AppSwitch id="integrity-enabled" v-model="policyForm.integrity_enabled" label="Activity integrity" />
                        Flag unusually repetitive activity for review
                    </label>
                    <p class="text-muted-foreground text-xs">
                        SprintSync Desktop looks only at when the computer's idle clock resets, to spot keep-awake tools. It never records keys, mouse
                        positions, apps, websites or screenshots. A flag is a suggestion to look, never an accusation, and your team is told it's on.
                    </p>
                </section>

                <div class="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-muted-foreground text-xs">
                        Changes apply from now on. Earlier time keeps the rules it was recorded under.
                        <template v-if="policy.effective_from"> Current rules since {{ formatWhen(policy.effective_from) }}.</template>
                    </p>
                    <Button type="submit" :disabled="policyForm.processing" data-testid="save-policy">
                        <Loader2 v-if="policyForm.processing" class="mr-2 size-4 animate-spin" />
                        Save policy
                    </Button>
                </div>
            </form>

            <!-- Schedules -->
            <form class="bg-card space-y-4 rounded-3xl border p-5 sm:p-6" data-testid="schedule-form" @submit.prevent="saveSchedule">
                <div>
                    <h2 class="text-sm font-semibold">Work schedule</h2>
                    <p class="text-muted-foreground mt-1 text-xs">
                        Expected hours, for reporting only. Schedules never create hours and never stop a timer; work outside them is simply shown.
                    </p>
                </div>
                <label class="flex flex-wrap items-center gap-2 text-sm">
                    For
                    <select v-model="schedulePerson" class="bg-background h-9 rounded-md border px-2">
                        <option value="">Everyone (default)</option>
                        <option v-for="person in people" :key="person.public_id" :value="person.public_id">{{ person.name }}</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <label
                        v-for="day in DAYS"
                        :key="day.value"
                        class="cursor-pointer rounded-lg border px-3 py-1.5 text-sm"
                        :class="scheduleForm.days.includes(day.value) && 'border-foreground bg-muted font-medium'"
                    >
                        <input v-model="scheduleForm.days" type="checkbox" :value="day.value" class="sr-only" />
                        {{ day.label }}
                    </label>
                </div>
                <div v-if="scheduleForm.days.length" class="flex flex-wrap items-center gap-2 text-sm">
                    <input v-model="scheduleForm.start_time" type="time" class="bg-background h-9 rounded-md border px-2" />
                    to
                    <input v-model="scheduleForm.end_time" type="time" class="bg-background h-9 rounded-md border px-2" />
                    <span v-if="overnight" class="text-muted-foreground text-xs">(ends the next day)</span>
                </div>
                <InputError :message="scheduleForm.errors.end_time || scheduleForm.errors.start_time || scheduleForm.errors.days" />
                <p v-if="!scheduleForm.days.length" class="text-muted-foreground text-xs">No days selected: no schedule.</p>
                <div class="flex justify-end">
                    <Button type="submit" variant="outline" :disabled="scheduleForm.processing">Save schedule</Button>
                </div>
            </form>

            <!-- Holidays -->
            <section class="bg-card space-y-4 rounded-3xl border p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><CalendarHeart class="size-4" /> Holidays</h2>
                <form class="grid gap-3 sm:grid-cols-[1fr_9rem_6rem_auto] sm:items-end" @submit.prevent="addHoliday">
                    <AppFormInput
                        id="holiday-name"
                        v-model="holidayForm.name"
                        label="Name"
                        placeholder="Christmas Day"
                        :error="holidayForm.errors.name"
                    />
                    <AppFormInput
                        id="holiday-date"
                        v-model="holidayForm.start_date"
                        type="date"
                        label="Date"
                        :error="holidayForm.errors.start_date"
                    />
                    <AppFormInput id="holiday-hours" v-model="holidayForm.hours" label="Paid hours" :error="holidayForm.errors.hours" />
                    <Button type="submit" :disabled="holidayForm.processing" data-testid="add-holiday">Add holiday</Button>
                </form>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-2"><input v-model="holidayForEveryone" type="radio" :value="true" /> Everyone</label>
                    <label class="flex items-center gap-2"><input v-model="holidayForEveryone" type="radio" :value="false" /> Selected people</label>
                </div>
                <div v-if="!holidayForEveryone" class="flex flex-wrap gap-2">
                    <label v-for="person in people" :key="person.public_id" class="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm">
                        <input v-model="holidayForm.people" type="checkbox" :value="person.public_id" /> {{ person.name }}
                    </label>
                </div>
                <p class="text-muted-foreground text-xs">Holiday hours count as paid time. They are never tracked or billed to clients.</p>
                <ul v-if="holidays.length" class="divide-y rounded-xl border" data-testid="holidays">
                    <li v-for="holiday in holidays" :key="holiday.public_id" class="flex items-center gap-3 px-3 py-2.5 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ holiday.name }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ formatPeriod(holiday.date, holiday.date) }} · {{ formatDuration(holiday.credit_seconds) }} paid ·
                                {{ holiday.people.length ? holiday.people.join(', ') : 'Everyone' }}
                            </p>
                        </div>
                        <Button variant="ghost" size="sm" :aria-label="`Remove ${holiday.name}`" @click="remove('holidays', holiday.public_id)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </li>
                </ul>
            </section>

            <!-- Leave -->
            <section class="bg-card space-y-4 rounded-3xl border p-5 sm:p-6">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><UserMinus class="size-4" /> Leave</h2>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="addLeave">
                    <div class="grid gap-1.5">
                        <Label for="leave-person">Person</Label>
                        <select id="leave-person" v-model="leaveForm.person" class="bg-background h-10 rounded-md border px-3 text-sm">
                            <option v-for="person in people" :key="person.public_id" :value="person.public_id">{{ person.name }}</option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="leave-type">Type</Label>
                        <select id="leave-type" v-model="leaveForm.type" class="bg-background h-10 rounded-md border px-3 text-sm">
                            <option value="paid">Paid leave</option>
                            <option value="unpaid">Unpaid leave</option>
                        </select>
                    </div>
                    <AppFormInput id="leave-start" v-model="leaveForm.start_date" type="date" label="From" :error="leaveForm.errors.start_date" />
                    <AppFormInput id="leave-end" v-model="leaveForm.end_date" type="date" label="To" :error="leaveForm.errors.end_date" />
                    <AppFormInput
                        v-if="leaveForm.type === 'paid'"
                        id="leave-hours"
                        v-model="leaveForm.hours"
                        label="Paid hours per working day"
                        :error="leaveForm.errors.hours"
                    />
                    <AppFormInput id="leave-note" v-model="leaveForm.note" label="Note" hint="Optional" :error="leaveForm.errors.note" />
                    <div class="flex justify-end sm:col-span-2">
                        <Button type="submit" :disabled="leaveForm.processing" data-testid="add-leave">Add leave</Button>
                    </div>
                </form>
                <p class="text-muted-foreground text-xs">Paid leave counts toward paid hours on working days. Neither kind is billed to clients.</p>
                <ul v-if="leaves.length" class="divide-y rounded-xl border" data-testid="leaves">
                    <li v-for="leave in leaves" :key="leave.public_id" class="flex items-center gap-3 px-3 py-2.5 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ leave.person }} · {{ leave.type === 'paid' ? 'Paid leave' : 'Unpaid leave' }}</p>
                            <p class="text-muted-foreground text-xs">
                                {{ formatPeriod(leave.start_date, leave.end_date)
                                }}<template v-if="leave.type === 'paid'"> · {{ formatDuration(leave.credit_seconds_per_day) }} a day</template
                                ><template v-if="leave.note"> · {{ leave.note }}</template>
                            </p>
                        </div>
                        <Button variant="ghost" size="sm" aria-label="Remove leave" @click="remove('leaves', leave.public_id)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </li>
                </ul>
            </section>

            <p v-if="history.length > 1" class="text-muted-foreground text-xs">
                Earlier policies:
                <template v-for="(version, index) in history.slice(1)" :key="version.effective_from">
                    {{ index ? ' · ' : '' }}{{ formatWhen(version.effective_from) }}<template v-if="version.by"> by {{ version.by }}</template>
                </template>
            </p>
        </div>
    </AppLayout>
</template>
