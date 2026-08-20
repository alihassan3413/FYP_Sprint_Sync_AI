<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarDays, Check, ChevronRight, Kanban, Lock, Menu, Mic, Play, Shield, Sparkles, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/* ------------------------------------------------------------------ *
 * The page obeys permissions.
 *
 * SprintSync's real differentiator is that the assistant is only handed
 * the tools the signed-in role allows. Rather than claim that, this page
 * enforces it on itself: pick a role and the command grid locks, the
 * counter drops, and the demo starts getting refused.
 *
 * Every access level below was read out of the tool authorize() methods
 * and the policies. Nothing here is decorative.
 * ------------------------------------------------------------------ */
type Role = 'owner' | 'member' | 'client';
type Access = 'full' | 'limited' | 'locked';

const roles: { id: Role; label: string; blurb: string }[] = [
    { id: 'owner', label: 'Owner', blurb: 'Runs the workspace. Every tool is on the table.' },
    { id: 'member', label: 'Member', blurb: 'Works inside their projects. Cannot run the workspace.' },
    { id: 'client', label: 'Client', blurb: 'An outside guest. Sees only the projects you add them to.' },
];

interface Command {
    name: string;
    group: string;
    access: Record<Role, Access>;
    note?: Partial<Record<Role, string>>;
}

const commands: Command[] = [
    { name: 'Teach me how to use SprintSync', group: 'Learn', access: { owner: 'full', member: 'full', client: 'full' } },
    { name: 'Create a workspace', group: 'Workspace', access: { owner: 'full', member: 'full', client: 'full' } },
    { name: 'Invite someone', group: 'Workspace', access: { owner: 'full', member: 'locked', client: 'locked' } },
    {
        name: 'Who is in this workspace',
        group: 'Workspace',
        access: { owner: 'full', member: 'full', client: 'limited' },
        note: { client: 'Never the team roster' },
    },
    {
        name: 'List projects',
        group: 'Projects',
        access: { owner: 'full', member: 'full', client: 'limited' },
        note: { member: 'Only projects they are on', client: 'Only projects they are on' },
    },
    { name: 'Create a project', group: 'Projects', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'Add someone to a project', group: 'Projects', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'Find tasks', group: 'Tasks', access: { owner: 'full', member: 'full', client: 'limited' } },
    {
        name: 'Create a task',
        group: 'Tasks',
        access: { owner: 'full', member: 'full', client: 'limited' },
        note: { client: 'Lands as a request to triage' },
    },
    {
        name: 'Update a task',
        group: 'Tasks',
        access: { owner: 'full', member: 'full', client: 'limited' },
        note: { client: 'Can close, never reopen' },
    },
    { name: 'Comment on a task', group: 'Tasks', access: { owner: 'full', member: 'full', client: 'full' } },
    {
        name: 'Delete a task',
        group: 'Tasks',
        access: { owner: 'full', member: 'limited', client: 'locked' },
        note: { member: 'Project managers only' },
    },
    { name: 'Plan, start or close a sprint', group: 'Sprints', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'Sprint status', group: 'Sprints', access: { owner: 'full', member: 'full', client: 'limited' } },
    { name: 'Schedule a meeting', group: 'Meetings', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'List meetings', group: 'Meetings', access: { owner: 'full', member: 'full', client: 'limited' } },
    { name: 'Change a meeting', group: 'Meetings', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'Cancel a meeting', group: 'Meetings', access: { owner: 'full', member: 'locked', client: 'locked' } },
    { name: 'How are we doing', group: 'Insights', access: { owner: 'full', member: 'full', client: 'locked' } },
];

const role = ref<Role>('owner');

const availableCount = computed(() => commands.filter((c) => c.access[role.value] !== 'locked').length);
const lockedCount = computed(() => commands.length - availableCount.value);
const activeRole = computed(() => roles.find((r) => r.id === role.value)!);

/* ------------------------------------------------------------------ *
 * The scripted demo, which changes with the role.
 * ------------------------------------------------------------------ */
interface Scene {
    chip: string;
    command: string;
    reply: string;
    confirm: { action: string; rows: [string, string][] } | null;
    denied?: boolean;
    effect: 'task' | 'sprint' | 'meeting' | 'close' | 'none';
}

const sceneSets: Record<Role, Scene[]> = {
    owner: [
        {
            chip: 'Create a task',
            command: 'Create a task to fix the login redirect, assign it to Sara, due Friday',
            reply: "I'll add that to Website Revamp and put it in To Do.",
            confirm: {
                action: 'Create task',
                rows: [
                    ['Title', 'Fix the login redirect'],
                    ['Project', 'Website Revamp'],
                    ['Assignee', 'Sara Ahmed'],
                    ['Due', 'Fri 22 Aug'],
                ],
            },
            effect: 'task',
        },
        {
            chip: 'Sprint status',
            command: 'How is the current sprint going?',
            reply: "Sprint 4 is 62% done with 3 days left. That's behind pace — two tasks are overdue.",
            confirm: null,
            effect: 'sprint',
        },
        {
            chip: 'Schedule a meeting',
            command: 'Schedule a sprint review Thursday at 4pm and invite the team',
            reply: 'Booking it for Thu 21 Aug, 16:00. Six participants will get an email invite.',
            confirm: {
                action: 'Schedule meeting',
                rows: [
                    ['Title', 'Sprint review'],
                    ['When', 'Thu 21 Aug, 16:00'],
                    ['Participants', '6 people'],
                ],
            },
            effect: 'meeting',
        },
    ],
    member: [
        {
            chip: 'Create a task',
            command: 'Create a task to audit the colour contrast, due Monday',
            reply: 'Added to Website Revamp, in To Do.',
            confirm: {
                action: 'Create task',
                rows: [
                    ['Title', 'Audit the colour contrast'],
                    ['Project', 'Website Revamp'],
                    ['Due', 'Mon 25 Aug'],
                ],
            },
            effect: 'task',
        },
        {
            chip: 'Sprint status',
            command: 'How is the current sprint going?',
            reply: 'Sprint 4 is 62% done with 3 days left. Two tasks are overdue.',
            confirm: null,
            effect: 'sprint',
        },
        {
            chip: 'Try scheduling a meeting',
            command: 'Schedule a sprint review for Thursday at 4pm',
            reply: 'Scheduling meetings is limited to project managers here, so I cannot book that for you.',
            confirm: null,
            denied: true,
            effect: 'none',
        },
    ],
    client: [
        {
            chip: 'Close a task',
            command: 'The checkout bug is fixed on my side — move it to Done',
            reply: "I'll move it into Done. You will not be able to move it back out.",
            confirm: {
                action: 'Update task',
                rows: [
                    ['Task', 'Checkout bug on mobile'],
                    ['Column', 'Done'],
                    ['Reversible', 'No'],
                ],
            },
            effect: 'close',
        },
        {
            chip: 'Sprint status',
            command: 'How is my project going?',
            reply: 'Sprint 4 on Website Revamp is 62% done with 3 days left.',
            confirm: null,
            effect: 'sprint',
        },
        {
            chip: 'Try inviting someone',
            command: 'Invite my developer omar@acme.com to the workspace',
            reply: 'Clients cannot invite people into a workspace. I have not sent anything.',
            confirm: null,
            denied: true,
            effect: 'none',
        },
    ],
};

const activeScenes = computed(() => sceneSets[role.value]);
const active = ref(0);
const typed = ref('');
const streamed = ref('');
const phase = ref<'typing' | 'thinking' | 'replying' | 'confirming' | 'settled'>('typing');

const scene = computed(() => activeScenes.value[active.value] ?? activeScenes.value[0]);
const showConfirm = computed(() => (phase.value === 'confirming' || phase.value === 'settled') && scene.value.confirm !== null);
const showDenied = computed(() => (phase.value === 'confirming' || phase.value === 'settled') && scene.value.denied === true);

const todo = ref<string[]>([]);
const doing = ref<string[]>([]);
const done = ref<string[]>([]);
const sprintPct = ref(0);
const sprintLive = ref(false);
const meeting = ref<{ when: string } | null>(null);

const boardColumns = computed(() => [
    { name: 'To Do', items: todo.value },
    { name: 'In Progress', items: doing.value },
    { name: 'Done', items: done.value },
]);

const reduceMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let cancelled = false;
let timers: ReturnType<typeof setTimeout>[] = [];

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => {
        timers.push(setTimeout(resolve, reduceMotion ? Math.min(ms, 120) : ms));
    });
}

function resetBoard(): void {
    todo.value = ['Rework empty states', 'Audit colour contrast'];
    doing.value = ['Checkout bug on mobile'];
    done.value = ['Ship invite links'];
    sprintPct.value = 0;
    sprintLive.value = false;
    meeting.value = null;
}

async function typeOut(text: string): Promise<void> {
    typed.value = '';

    if (reduceMotion) {
        typed.value = text;

        return;
    }

    for (const character of text) {
        if (cancelled) return;
        typed.value += character;
        await wait(17);
    }
}

async function streamOut(text: string): Promise<void> {
    streamed.value = '';

    if (reduceMotion) {
        streamed.value = text;

        return;
    }

    for (const word of text.split(' ')) {
        if (cancelled) return;
        streamed.value += (streamed.value === '' ? '' : ' ') + word;
        await wait(36);
    }
}

function applyEffect(effect: Scene['effect']): void {
    /* The columns have reserved heights, so they are capped rather than grown. */
    if (effect === 'task') {
        todo.value = [scene.value.confirm?.rows[0][1] ?? 'New task', ...todo.value].slice(0, 3);
    }

    if (effect === 'close') {
        doing.value = [];
        done.value = ['Checkout bug on mobile', ...done.value].slice(0, 3);
    }

    if (effect === 'sprint') {
        sprintLive.value = true;
        sprintPct.value = 62;
    }

    if (effect === 'meeting') {
        meeting.value = { when: 'Thu 21 Aug · 16:00' };
    }
}

async function playScene(index: number): Promise<void> {
    active.value = index;
    resetBoard();
    streamed.value = '';
    phase.value = 'typing';

    await typeOut(activeScenes.value[index].command);
    if (cancelled) return;

    phase.value = 'thinking';
    await wait(600);
    if (cancelled) return;

    phase.value = 'replying';
    await streamOut(activeScenes.value[index].reply);
    if (cancelled) return;

    phase.value = 'confirming';
    await wait(480);
    if (cancelled) return;

    applyEffect(activeScenes.value[index].effect);
    phase.value = 'settled';
}

async function loop(): Promise<void> {
    while (!cancelled) {
        await playScene(active.value);
        if (cancelled) return;

        await wait(3000);
        if (cancelled) return;

        active.value = (active.value + 1) % activeScenes.value.length;
    }
}

function restart(index = 0): void {
    cancelled = true;
    timers.forEach(clearTimeout);
    timers = [];

    window.setTimeout(() => {
        cancelled = false;
        active.value = index;
        void loop();
    }, 40);
}

function chooseRole(next: Role): void {
    role.value = next;
}

/* Switching role rewrites the demo, so it starts that role's story over. */
watch(role, () => restart(0));

const menuOpen = ref(false);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    resetBoard();

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer?.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.08 },
    );

    document.querySelectorAll('[data-reveal]').forEach((node) => observer?.observe(node));

    void loop();
});

onBeforeUnmount(() => {
    cancelled = true;
    timers.forEach(clearTimeout);
    observer?.disconnect();
});

const navLinks = [
    { label: 'The lens', href: '#lens' },
    { label: 'How it works', href: '#how' },
    { label: 'For agencies', href: '#agencies' },
];

const pillars = [
    {
        t: 'It asks before it acts.',
        d: 'Anything that creates, changes or deletes shows a confirmation card first. Nothing is written on a guess.',
    },
    {
        t: "It can't outrank you.",
        d: 'The assistant is only handed the tools your role allows. There is no tool there to call for the rest.',
    },
    {
        t: 'It leaves a trail.',
        d: 'Workspace and project activity is written to an audit log you can search.',
    },
];

const clientCapabilities = ['View project board & sprints', 'Comment on tasks', 'Request tasks', 'Close tasks — never reopen them', 'View meetings'];
</script>
<template>
    <Head title="SprintSync — the sprint board you talk to" />

    <div class="ss">
        <div class="mx-auto max-w-[1320px] px-3 py-3 sm:px-4 sm:py-4">
            <!-- ==================== HERO ==================== -->
            <section class="relative overflow-hidden rounded-[28px] bg-[var(--ss-lavender)] p-4 sm:rounded-[40px] sm:p-6 lg:p-8">
                <div class="ss-grain" aria-hidden="true"></div>

                <nav class="relative flex items-center justify-between gap-3">
                    <Link :href="route('home')" class="group flex shrink-0 items-center gap-2.5">
                        <span
                            class="grid size-9 place-items-center rounded-full bg-[var(--ss-ink)] transition-transform duration-300 group-hover:rotate-12"
                        >
                            <Sparkles class="size-4 text-[var(--ss-lime)]" :stroke-width="2.5" />
                        </span>
                        <span class="text-[17px] font-extrabold tracking-tight text-[var(--ss-ink)]">sprintsync</span>
                    </Link>

                    <div class="hidden items-center gap-1 md:flex">
                        <a
                            v-for="link in navLinks"
                            :key="link.href"
                            :href="link.href"
                            class="rounded-full px-5 py-2.5 text-[14px] font-bold text-[var(--ss-ink)]/70 transition-all duration-200 hover:bg-white hover:text-[var(--ss-ink)]"
                        >
                            {{ link.label }}
                        </a>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('login')"
                            class="hidden rounded-full px-5 py-2.5 text-[14px] font-bold text-[var(--ss-ink)]/70 transition-colors hover:text-[var(--ss-ink)] sm:block"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="group hidden items-center gap-2 rounded-full bg-[var(--ss-ink)] py-2.5 pr-2.5 pl-5 text-[14px] font-bold text-white transition-transform duration-200 hover:-translate-y-0.5 sm:flex"
                        >
                            Get started
                            <span
                                class="grid size-7 place-items-center rounded-full bg-[var(--ss-lime)] transition-transform duration-300 group-hover:rotate-45"
                            >
                                <ArrowUpRight class="size-3.5 text-[var(--ss-ink)]" :stroke-width="3" />
                            </span>
                        </Link>

                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-full bg-white text-[var(--ss-ink)] md:hidden"
                            :aria-expanded="menuOpen"
                            aria-label="Menu"
                            @click="menuOpen = !menuOpen"
                        >
                            <component :is="menuOpen ? X : Menu" class="size-5" :stroke-width="2.5" />
                        </button>
                    </div>
                </nav>

                <div v-if="menuOpen" class="relative mt-3 rounded-3xl bg-white p-2 md:hidden">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="block rounded-2xl px-4 py-3 text-[15px] font-bold text-[var(--ss-ink)] hover:bg-[var(--ss-lavender)]"
                        @click="menuOpen = false"
                    >
                        {{ link.label }}
                    </a>

                    <div class="mt-1 flex gap-2 border-t border-[rgba(11,11,15,0.08)] pt-2">
                        <Link :href="route('login')" class="flex-1 rounded-2xl px-4 py-3 text-center text-[15px] font-bold text-[var(--ss-ink)]/70">
                            Log in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="flex-1 rounded-2xl bg-[var(--ss-ink)] px-4 py-3 text-center text-[15px] font-bold text-white"
                        >
                            Get started
                        </Link>
                    </div>
                </div>

                <div class="relative mt-8 grid grid-cols-1 items-stretch gap-5 lg:mt-6 lg:grid-cols-12 lg:gap-6">
                    <div class="order-1 flex flex-col justify-center lg:col-span-7">
                        <span class="text-[12px] font-extrabold tracking-[0.16em] text-[var(--ss-ink)]/45 uppercase">
                            [ AI-native sprint management ]
                        </span>

                        <h1 class="mt-5 text-[clamp(2.5rem,5.4vw,4.5rem)] leading-[0.95] font-extrabold tracking-[-0.035em] text-[var(--ss-ink)]">
                            Run your
                            <span class="ss-glyph"><Kanban class="size-[0.62em] text-[var(--ss-lime)]" :stroke-width="2.5" /></span>
                            sprint by <span class="ss-mark">saying so.</span>
                        </h1>

                        <p class="mt-6 max-w-[44ch] text-[clamp(0.98rem,1.2vw,1.15rem)] leading-[1.55] font-medium text-[var(--ss-ink)]/60">
                            One sentence becomes a task, a sprint, a meeting or a report — and the assistant can never do a thing your role would not
                            let you do yourself.
                        </p>

                        <!-- THE LENS -->
                        <div class="mt-8 rounded-[24px] bg-white/60 p-2 backdrop-blur-sm">
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-for="option in roles"
                                    :key="option.id"
                                    type="button"
                                    class="ss-roll flex-1 rounded-[18px] px-3 py-3 text-[13.5px] font-extrabold transition-all duration-300"
                                    :class="
                                        role === option.id
                                            ? 'bg-[var(--ss-ink)] text-white shadow-[0_6px_20px_rgba(11,11,15,0.22)]'
                                            : 'text-[var(--ss-ink)]/50 hover:bg-white hover:text-[var(--ss-ink)]'
                                    "
                                    @click="chooseRole(option.id)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                            <p class="px-3 pt-2.5 pb-1.5 text-[12.5px] leading-snug font-semibold text-[var(--ss-ink)]/55">
                                {{ activeRole.blurb }}
                            </p>
                        </div>

                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <Link
                                :href="route('register')"
                                class="group inline-flex items-center gap-3 rounded-full bg-[var(--ss-lime)] py-4 pr-4 pl-7 text-[15px] font-extrabold text-[var(--ss-ink)] transition-transform duration-200 hover:-translate-y-0.5"
                            >
                                Get Started
                                <span
                                    class="grid size-8 place-items-center rounded-full bg-[var(--ss-ink)] transition-transform duration-300 group-hover:rotate-45"
                                >
                                    <ArrowUpRight class="size-4 text-[var(--ss-lime)]" :stroke-width="2.5" />
                                </span>
                            </Link>

                            <a
                                href="#lens"
                                class="group inline-flex items-center gap-3 rounded-full bg-white py-4 pr-7 pl-3 text-[15px] font-bold text-[var(--ss-ink)] transition-transform duration-200 hover:-translate-y-0.5"
                            >
                                <span class="grid size-8 place-items-center rounded-full bg-[var(--ss-ink)] text-white">
                                    <Play class="size-3.5 fill-current" />
                                </span>
                                Watch it lock
                            </a>
                        </div>
                    </div>

                    <!-- demo -->
                    <div class="order-2 lg:col-span-5">
                        <div class="ss-demo flex flex-col rounded-[24px] bg-[var(--ss-ink)] p-5">
                            <div class="flex h-8 shrink-0 items-center justify-between">
                                <span class="text-[11px] font-extrabold tracking-[0.16em] text-white/40 uppercase">
                                    Signed in as {{ activeRole.label }}
                                </span>
                                <span class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white/70">
                                    <span class="size-1.5 rounded-full bg-[var(--ss-lime)]"></span>
                                    scripted
                                </span>
                            </div>

                            <div class="mt-4 shrink-0 rounded-2xl bg-white/[0.06] p-4 ring-1 ring-white/10">
                                <div class="h-[92px] overflow-hidden sm:h-[84px]">
                                    <p class="font-mono text-[13.5px] leading-[1.5] text-white">
                                        {{ typed }}<span v-if="phase === 'typing'" class="ss-caret"></span>
                                    </p>
                                </div>

                                <div class="mt-2 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="rounded-lg bg-white/10 px-2 py-1 font-mono text-[11px] font-bold text-white/60">/</span>
                                        <Mic class="size-4 text-white/40" :stroke-width="2.5" />
                                    </div>
                                    <span class="grid size-8 place-items-center rounded-full bg-[var(--ss-lime)]">
                                        <ArrowUpRight class="size-4 text-[var(--ss-ink)]" :stroke-width="3" />
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 h-[96px] shrink-0 overflow-hidden sm:h-[80px]">
                                <div v-if="phase === 'thinking'" class="flex items-center gap-2 px-1 pt-1">
                                    <span class="ss-dot"></span><span class="ss-dot ss-dot-2"></span><span class="ss-dot ss-dot-3"></span>
                                    <span class="ml-1 text-[12px] font-semibold text-white/40">SprintSync is working</span>
                                </div>
                                <p v-else class="px-1 text-[14.5px] leading-[1.5] font-medium text-white/90">{{ streamed }}</p>
                            </div>

                            <div class="mt-3 h-[188px] shrink-0">
                                <div v-if="showConfirm" class="ss-pop rounded-2xl bg-white/[0.07] p-4 ring-1 ring-[var(--ss-lime)]/40">
                                    <div class="flex items-center gap-2">
                                        <Shield class="size-4 text-[var(--ss-lime)]" :stroke-width="2.5" />
                                        <span class="text-[13px] font-extrabold text-white">Confirm this action</span>
                                    </div>

                                    <dl class="mt-3 space-y-1.5">
                                        <div v-for="row in scene.confirm?.rows" :key="row[0]" class="flex justify-between gap-4">
                                            <dt class="text-[12px] font-semibold text-white/40">{{ row[0] }}</dt>
                                            <dd class="text-[12px] font-bold text-white">{{ row[1] }}</dd>
                                        </div>
                                    </dl>

                                    <div class="mt-3 flex justify-end gap-2">
                                        <span class="rounded-full px-3 py-1.5 text-[12px] font-bold text-white/50">Cancel</span>
                                        <span class="rounded-full bg-[var(--ss-lime)] px-4 py-1.5 text-[12px] font-extrabold text-[var(--ss-ink)]"
                                            >Confirm</span
                                        >
                                    </div>
                                </div>

                                <div
                                    v-else-if="showDenied"
                                    class="ss-shake rounded-2xl bg-[rgba(251,113,133,0.10)] p-4 ring-1 ring-[var(--ss-rose)]/40"
                                >
                                    <div class="flex items-center gap-2">
                                        <Lock class="size-4 text-[var(--ss-rose)]" :stroke-width="2.5" />
                                        <span class="text-[13px] font-extrabold text-[var(--ss-rose)]">Not available to {{ activeRole.label }}</span>
                                    </div>
                                    <p class="mt-2 text-[12.5px] leading-relaxed font-semibold text-[var(--ss-rose)]/80">
                                        The tool was never offered to the assistant, so nothing was written and nobody was emailed.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ==================== BENTO ==================== -->
            <div class="mt-3 grid grid-cols-1 gap-3 sm:mt-4 sm:gap-4 lg:grid-cols-12">
                <div class="ss-bento flex flex-col rounded-[28px] bg-white p-5 sm:p-6 lg:col-span-5">
                    <div class="flex h-7 shrink-0 items-center justify-between">
                        <span class="flex items-center gap-2 text-[11px] font-extrabold tracking-[0.14em] text-[var(--ss-ink)]/40 uppercase">
                            <Kanban class="size-3.5" :stroke-width="2.5" /> Board
                        </span>
                        <span
                            v-if="meeting"
                            class="ss-pop flex items-center gap-1.5 rounded-full bg-[var(--ss-lavender)] px-3 py-1.5 text-[11px] font-bold text-[var(--ss-indigo)]"
                        >
                            <CalendarDays class="size-3" :stroke-width="2.5" /> {{ meeting.when }}
                        </span>
                    </div>

                    <div class="mt-4 grid flex-1 grid-cols-3 gap-1.5 sm:gap-2.5">
                        <div v-for="col in boardColumns" :key="col.name">
                            <p
                                class="mb-2 flex items-center gap-1 text-[9px] font-extrabold tracking-wide text-[var(--ss-ink)]/35 uppercase sm:gap-1.5 sm:text-[10px] sm:tracking-wider"
                            >
                                {{ col.name }}
                                <span class="rounded-full bg-[var(--ss-paper)] px-1.5 py-0.5">{{ col.items.length }}</span>
                            </p>
                            <div class="h-[152px] space-y-2 overflow-hidden">
                                <div
                                    v-for="card in col.items"
                                    :key="card"
                                    class="ss-pop rounded-lg bg-[var(--ss-paper)] p-2 text-[10.5px] leading-snug font-bold text-[var(--ss-ink)] sm:rounded-xl sm:p-2.5 sm:text-[11px]"
                                >
                                    {{ card }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ss-bento flex flex-col justify-between rounded-[28px] bg-[var(--ss-indigo)] p-5 text-white sm:p-6 lg:col-span-3">
                    <div class="flex h-7 shrink-0 items-start justify-between">
                        <span class="text-[11px] font-extrabold tracking-[0.14em] text-white/55 uppercase">[ Sprint 4 ]</span>
                        <span
                            v-if="sprintLive"
                            class="ss-pop rounded-full bg-[var(--ss-lime)] px-3 py-1 text-[11px] font-extrabold text-[var(--ss-ink)]"
                        >
                            At risk
                        </span>
                    </div>

                    <div>
                        <p class="text-[clamp(2.75rem,5vw,3.5rem)] leading-none font-extrabold tracking-tight">
                            {{ sprintPct }}<span class="text-[0.5em]">%</span>
                        </p>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/20">
                            <div
                                class="h-full rounded-full bg-[var(--ss-lime)] transition-all duration-[900ms] ease-out"
                                :style="{ width: sprintPct + '%' }"
                            ></div>
                        </div>
                        <p class="mt-3 text-[13px] leading-snug font-bold text-white/70">Scope done against time gone. Three days left.</p>
                    </div>
                </div>

                <div class="ss-bento flex flex-col justify-between rounded-[28px] bg-[var(--ss-ink)] p-5 sm:p-6 lg:col-span-4">
                    <span class="h-7 shrink-0 text-[11px] font-extrabold tracking-[0.14em] text-white/40 uppercase">[ Available to you ]</span>
                    <div>
                        <p class="flex items-baseline gap-2 text-[clamp(2.75rem,5vw,3.5rem)] leading-none font-extrabold tracking-tight text-white">
                            <span class="ss-count">{{ availableCount }}</span>
                            <span class="text-[0.42em] font-bold text-white/40">of {{ commands.length }} tools</span>
                        </p>
                        <p class="mt-2 text-[14.5px] leading-snug font-bold text-white/80">
                            {{ lockedCount === 0 ? 'Nothing is withheld from an owner.' : lockedCount + ' are not handed to the assistant at all.' }}
                        </p>
                        <a
                            href="#lens"
                            class="mt-4 inline-flex items-center gap-1 text-[13px] font-extrabold text-[var(--ss-lime)] transition-all hover:gap-2"
                        >
                            See which <ChevronRight class="size-4" :stroke-width="3" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- ==================== TICKER ==================== -->
            <div class="ss-ticker mt-3 overflow-hidden rounded-full bg-[var(--ss-lime)] py-3.5 sm:mt-4" aria-hidden="true">
                <div class="ss-ticker-track flex w-max items-center gap-8">
                    <template v-for="pass in 2" :key="pass">
                        <span v-for="command in commands" :key="pass + command.name" class="flex shrink-0 items-center gap-8">
                            <span class="text-[14px] font-extrabold tracking-tight whitespace-nowrap text-[var(--ss-ink)]">
                                {{ command.name }}
                            </span>
                            <Sparkles class="size-3.5 shrink-0 text-[var(--ss-ink)]/40" :stroke-width="3" />
                        </span>
                    </template>
                </div>
            </div>

            <!-- ==================== THE LENS ==================== -->
            <section id="lens" data-reveal class="ss-reveal mt-3 sm:mt-4">
                <div class="rounded-[28px] bg-white p-6 sm:rounded-[40px] sm:p-12">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <span class="text-[12px] font-extrabold tracking-[0.16em] text-[var(--ss-indigo)] uppercase"
                                >[ The permission lens ]</span
                            >
                            <h2
                                class="mt-4 max-w-[22ch] text-[clamp(2rem,4.2vw,3.4rem)] leading-[1.02] font-extrabold tracking-[-0.03em] text-[var(--ss-ink)]"
                            >
                                This page obeys permissions. Flip a role and watch.
                            </h2>
                            <p class="mt-4 max-w-[54ch] text-[15px] leading-relaxed font-medium text-[var(--ss-ink)]/60">
                                Nineteen tools, and not one of them is offered to someone who should not have it. This is the real authorization
                                model, not an illustration of one.
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1.5 self-stretch rounded-[20px] bg-[var(--ss-paper)] p-1.5 lg:self-auto">
                            <button
                                v-for="option in roles"
                                :key="option.id"
                                type="button"
                                class="flex-1 rounded-[14px] px-3 py-3 text-[13px] font-extrabold transition-all duration-300 sm:px-4 lg:flex-none lg:py-2.5"
                                :class="role === option.id ? 'bg-[var(--ss-ink)] text-white' : 'text-[var(--ss-ink)]/50 hover:text-[var(--ss-ink)]'"
                                @click="chooseRole(option.id)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-10 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(command, index) in commands"
                            :key="command.name"
                            :style="{ transitionDelay: index * 26 + 'ms' }"
                            class="ss-cmd flex items-center gap-3 rounded-2xl px-4 py-3.5 transition-all duration-500"
                            :class="{
                                'bg-[var(--ss-paper)]': command.access[role] === 'full',
                                'bg-[var(--ss-lime)]': command.access[role] === 'limited',
                                'ss-locked bg-[var(--ss-paper)]/50': command.access[role] === 'locked',
                            }"
                        >
                            <span
                                class="grid size-7 shrink-0 place-items-center rounded-full transition-colors duration-500"
                                :class="command.access[role] === 'locked' ? 'bg-[var(--ss-ink)]/10' : 'bg-[var(--ss-ink)]'"
                            >
                                <component
                                    :is="command.access[role] === 'locked' ? Lock : Check"
                                    class="size-3.5"
                                    :class="command.access[role] === 'locked' ? 'text-[var(--ss-ink)]/40' : 'text-[var(--ss-lime)]'"
                                    :stroke-width="3"
                                />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-[13.5px] leading-snug font-bold transition-colors duration-500"
                                    :class="command.access[role] === 'locked' ? 'text-[var(--ss-ink)]/35 line-through' : 'text-[var(--ss-ink)]'"
                                >
                                    {{ command.name }}
                                </span>
                                <span
                                    v-if="command.note?.[role] && command.access[role] !== 'locked'"
                                    class="block text-[11px] leading-snug font-bold text-[var(--ss-ink)]/50"
                                >
                                    {{ command.note[role] }}
                                </span>
                            </span>
                        </div>
                    </div>

                    <p class="mt-8 text-[13px] font-medium text-[var(--ss-ink)]/40">
                        Type <span class="rounded bg-[var(--ss-paper)] px-1.5 py-0.5 font-mono font-bold text-[var(--ss-ink)]/70">/</span> in the app
                        to search whichever of these you are allowed to use, in plain language.
                    </p>
                </div>
            </section>

            <!-- ==================== TRUST ==================== -->
            <section id="how" data-reveal class="ss-reveal px-2 py-20 sm:px-4 sm:py-28">
                <div class="grid gap-10 md:grid-cols-3">
                    <div v-for="pillar in pillars" :key="pillar.t">
                        <span class="grid size-10 place-items-center rounded-full bg-[var(--ss-lime)]">
                            <Check class="size-5 text-[var(--ss-ink)]" :stroke-width="3" />
                        </span>
                        <h3 class="mt-5 text-[clamp(1.4rem,2vw,1.8rem)] leading-tight font-extrabold tracking-tight text-[var(--ss-ink)]">
                            {{ pillar.t }}
                        </h3>
                        <p class="mt-3 text-[15px] leading-relaxed font-medium text-[var(--ss-ink)]/60">{{ pillar.d }}</p>
                    </div>
                </div>
            </section>

            <!-- ==================== AGENCIES ==================== -->
            <section id="agencies" data-reveal class="ss-reveal grid grid-cols-1 items-stretch gap-3 sm:gap-4 lg:grid-cols-12">
                <div class="rounded-[28px] bg-[var(--ss-lavender)] p-6 sm:rounded-[40px] sm:p-10 lg:col-span-7">
                    <span class="text-[12px] font-extrabold tracking-[0.16em] text-[var(--ss-indigo)] uppercase">[ For agencies ]</span>
                    <h2 class="mt-4 text-[clamp(1.9rem,3.6vw,3rem)] leading-[1.04] font-extrabold tracking-[-0.03em] text-[var(--ss-ink)]">
                        Give the client the board.<br />Not your Slack.
                    </h2>
                    <p class="mt-4 max-w-[52ch] text-[15px] leading-relaxed font-medium text-[var(--ss-ink)]/60">
                        Clients are a role of their own, not a member you hope behaves. They never see your team roster, your other projects, or your
                        workspace settings — and you pick exactly what they can do inside the projects they're on.
                    </p>
                </div>

                <div class="rounded-[28px] bg-white p-6 sm:rounded-[40px] sm:p-10 lg:col-span-5">
                    <div
                        v-for="capability in clientCapabilities"
                        :key="capability"
                        class="flex items-center gap-3 border-b border-[rgba(11,11,15,0.07)] py-3 last:border-0"
                    >
                        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-[var(--ss-lime)]">
                            <Check class="size-3.5 text-[var(--ss-ink)]" :stroke-width="3" />
                        </span>
                        <span class="text-[14px] font-bold text-[var(--ss-ink)]">{{ capability }}</span>
                    </div>
                    <p class="mt-4 text-[13px] font-medium text-[var(--ss-ink)]/40">
                        Off by default. A new client sees the board and nothing else until you say otherwise.
                    </p>
                </div>
            </section>

            <!-- ==================== CTA ==================== -->
            <section data-reveal class="ss-reveal mt-3 sm:mt-4">
                <div class="rounded-[28px] bg-[var(--ss-lime)] px-6 py-20 text-center sm:rounded-[40px] sm:px-12">
                    <h2
                        class="mx-auto max-w-[16ch] text-[clamp(2.25rem,5.2vw,4rem)] leading-[0.98] font-extrabold tracking-[-0.035em] text-[var(--ss-ink)]"
                    >
                        Stop managing the tool.
                    </h2>
                    <p class="mx-auto mt-5 max-w-[46ch] text-[16px] leading-relaxed font-medium text-[var(--ss-ink)]/65">
                        Set up a workspace, invite your team, and run your first sprint by talking to it.
                    </p>

                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <Link
                            :href="route('register')"
                            class="group inline-flex items-center gap-3 rounded-full bg-[var(--ss-ink)] py-4 pr-4 pl-7 text-[15px] font-extrabold text-white transition-transform duration-200 hover:-translate-y-0.5"
                        >
                            Get started free
                            <span
                                class="grid size-8 place-items-center rounded-full bg-[var(--ss-lime)] transition-transform duration-300 group-hover:rotate-45"
                            >
                                <ArrowUpRight class="size-4 text-[var(--ss-ink)]" :stroke-width="2.5" />
                            </span>
                        </Link>
                        <Link
                            :href="route('login')"
                            class="rounded-full px-7 py-4 text-[15px] font-bold text-[var(--ss-ink)]/60 transition-colors hover:text-[var(--ss-ink)]"
                        >
                            Sign in
                        </Link>
                    </div>
                </div>
            </section>

            <footer class="mt-4 flex flex-col items-center justify-between gap-4 px-2 py-8 sm:flex-row sm:px-4">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-8 place-items-center rounded-full bg-[var(--ss-lime)]">
                        <Sparkles class="size-4 text-[var(--ss-ink)]" :stroke-width="2.5" />
                    </span>
                    <span class="text-[14px] font-extrabold text-[var(--ss-ink)]">sprintsync</span>
                    <span class="text-[13px] font-semibold text-[var(--ss-ink)]/45">AI-native sprint management.</span>
                </div>
                <p class="text-[13px] font-semibold text-[var(--ss-ink)]/45">© 2026 SprintSync · Powered by ITG.</p>
            </footer>
        </div>
    </div>
</template>

<style>
/*
 * The landing page is one deliberate light composition, so it must not follow
 * the visitor's dark-mode preference.
 *
 * `initializeTheme()` puts `.dark` on <html>, and the app's base layer paints
 * `body` with `--background`, which the dark theme redefines to a navy. That
 * navy shows through the iOS overscroll bounce and anywhere the page does not
 * cover. Scoping to `html:has(.ss)` applies it only while this page is mounted,
 * and releases the moment Inertia navigates into the app.
 */
html:has(.ss),
html:has(.ss) body {
    background-color: #efefea !important;
    color-scheme: light !important;
}

html:has(.ss) {
    overflow-x: hidden;
}
</style>

<style scoped>
/*
 * The page owns its palette. The app's global tokens are a lime light theme
 * and an unrelated blue dark theme, so inheriting them would make the landing
 * page change identity with the visitor's preference.
 */
.ss {
    --ss-ink: #0b0b0f;
    --ss-ink-dim: #55565f;
    --ss-ink-faint: #8b8c96;
    --ss-paper: #efefea;
    --ss-lavender: #e4e3ff;
    --ss-lime: #baff1a;
    --ss-indigo: #365aff;
    --ss-indigo-hot: #2647e6;
    --ss-rose: #fb7185;

    background: var(--ss-paper);
    color: var(--ss-ink);
    min-height: 100vh;
}

/*
 * Every region the demo writes into has a reserved height. The typed command,
 * the streamed reply and the confirmation card all change size as the loop
 * runs, and without this the whole page would shift under the reader.
 */
.ss-demo {
    /* The reserved slots inside sum to roughly this. A narrow screen wraps the
       typed command onto more lines, so mobile needs the taller value. */
    height: 596px;
}

.ss-bento {
    min-height: 260px;
}

@media (min-width: 1024px) {
    .ss-demo {
        height: 100%;
        min-height: 560px;
    }

    .ss-bento {
        height: 300px;
    }
}

/* Inline glyph sitting in the headline, like a word made of product. */
.ss-glyph {
    display: inline-grid;
    place-items: center;
    vertical-align: baseline;
    width: 1.05em;
    height: 1.05em;
    border-radius: 0.26em;
    background: var(--ss-ink);
    transform: translateY(0.08em) rotate(-4deg);
}

.ss-grain {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(50% 60% at 88% 8%, rgba(54, 90, 255, 0.16), transparent 70%),
        radial-gradient(40% 50% at 4% 96%, rgba(186, 255, 26, 0.28), transparent 70%);
}

/* Marker swipe behind the emphasised words. */
.ss-mark {
    position: relative;
    display: inline-block;
    white-space: nowrap;
}

.ss-mark::after {
    content: '';
    position: absolute;
    inset: 12% -0.12em 8%;
    background: var(--ss-lime);
    z-index: -1;
    transform: scaleX(0);
    transform-origin: left;
    animation: ss-swipe 620ms cubic-bezier(0.2, 0.8, 0.2, 1) 380ms forwards;
    border-radius: 4px;
}

@keyframes ss-swipe {
    to {
        transform: scaleX(1);
    }
}

.ss-caret {
    display: inline-block;
    width: 2px;
    height: 1.05em;
    margin-left: 1px;
    background: var(--ss-lime);
    vertical-align: text-bottom;
    animation: ss-blink 1s steps(2, start) infinite;
}

@keyframes ss-blink {
    50% {
        opacity: 0;
    }
}

.ss-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #a78bfa;
    animation: ss-bounce 900ms ease-in-out infinite;
}

.ss-dot-2 {
    animation-delay: 140ms;
}

.ss-dot-3 {
    animation-delay: 280ms;
}

@keyframes ss-bounce {
    0%,
    100% {
        opacity: 0.35;
        transform: translateY(0);
    }
    50% {
        opacity: 1;
        transform: translateY(-3px);
    }
}

.ss-pop {
    animation: ss-pop 280ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

@keyframes ss-pop {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.98);
    }
}

.ss-reveal {
    opacity: 0;
    transform: translateY(14px);
    transition:
        opacity 420ms cubic-bezier(0.2, 0.8, 0.2, 1),
        transform 420ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.ss-reveal.is-revealed {
    opacity: 1;
    transform: none;
}

/*
 * A band of every command the product actually has, running past forever.
 * Duplicated once so the translate can loop seamlessly at -50%.
 */
.ss-ticker-track {
    animation: ss-marquee 46s linear infinite;
}

.ss-ticker:hover .ss-ticker-track {
    animation-play-state: paused;
}

@keyframes ss-marquee {
    to {
        transform: translateX(-50%);
    }
}

/* Paper tooth, so the flat background is not actually flat. */
.ss::before {
    content: '';
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    opacity: 0.5;
    background-image: radial-gradient(circle at 1px 1px, rgba(11, 11, 15, 0.055) 1px, transparent 0);
    background-size: 22px 22px;
}

.ss > * {
    position: relative;
    z-index: 1;
}

/* A locked command physically recedes: desaturated, inset, unreachable. */
.ss-locked {
    filter: saturate(0.15);
    transform: scale(0.985);
    box-shadow: inset 0 0 0 1px rgba(11, 11, 15, 0.06);
}

/* The refusal is felt, not just read. */
.ss-shake {
    animation: ss-shake 420ms cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}

@keyframes ss-shake {
    0% {
        opacity: 0;
        transform: translateY(8px);
    }
    30% {
        opacity: 1;
        transform: translateX(-5px);
    }
    50% {
        transform: translateX(4px);
    }
    70% {
        transform: translateX(-2px);
    }
    100% {
        transform: translateX(0);
    }
}

.ss-count {
    display: inline-block;
    animation: ss-tick 420ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes ss-tick {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
}

/* Role buttons press in rather than just recolouring. */
.ss-roll:active {
    transform: scale(0.97);
}

@media (prefers-reduced-motion: reduce) {
    .ss-mark::after {
        animation: none;
        transform: scaleX(1);
    }

    .ss-caret,
    .ss-dot,
    .ss-pop,
    .ss-shake,
    .ss-count {
        animation: none;
    }

    .ss-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }

    .ss-ticker-track {
        animation: none;
    }

    .ss-cmd {
        transition-delay: 0ms !important;
    }
}
</style>
