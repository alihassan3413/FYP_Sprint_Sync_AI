<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarDays, Check, ChevronRight, Kanban, Menu, Mic, Play, Shield, Sparkles, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/* ------------------------------------------------------------------ *
 * The scripted hero demo.
 *
 * Every command below is a real action the assistant performs. The last
 * scene is a refusal on purpose: "it cannot outrank you" is the objection
 * that actually closes people, and showing it beats claiming it.
 * ------------------------------------------------------------------ */
interface Scene {
    id: string;
    chip: string;
    command: string;
    reply: string;
    confirm: { action: string; rows: [string, string][] } | null;
    denied?: boolean;
    effect: 'task' | 'sprint' | 'meeting' | 'none';
}

const scenes: Scene[] = [
    {
        id: 'task',
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
        id: 'sprint',
        chip: 'Sprint status',
        command: 'How is the current sprint going?',
        reply: "Sprint 4 is 62% done with 3 days left. That's behind pace — two tasks are overdue.",
        confirm: null,
        effect: 'sprint',
    },
    {
        id: 'meeting',
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
    {
        id: 'denied',
        chip: 'Try something you cannot do',
        command: 'Delete the Website Revamp project',
        reply: "You don't have permission to delete projects in this workspace. I've asked the owner instead.",
        confirm: null,
        denied: true,
        effect: 'none',
    },
];

const active = ref(0);
const typed = ref('');
const streamed = ref('');
const phase = ref<'typing' | 'thinking' | 'replying' | 'confirming' | 'settled'>('typing');

const scene = computed(() => scenes[active.value]);
const showConfirm = computed(() => (phase.value === 'confirming' || phase.value === 'settled') && scene.value.confirm !== null);
const showDenied = computed(() => (phase.value === 'confirming' || phase.value === 'settled') && scene.value.denied === true);

/* Board state the demo mutates, so the reply visibly does something. */
const todo = ref(['Rework empty states', 'Audit colour contrast']);
const doing = ref(['Checkout bug on mobile']);
const done = ref(['Ship invite links']);
const sprintPct = ref(0);
const sprintLive = ref(false);
const meeting = ref<{ title: string; when: string } | null>(null);

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
        await wait(18);
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
        await wait(38);
    }
}

function applyEffect(effect: Scene['effect']): void {
    if (effect === 'task') {
        /* Capped: the column has a reserved height, so it must never grow. */
        todo.value = ['Fix the login redirect', ...todo.value].slice(0, 3);
    }

    if (effect === 'sprint') {
        sprintLive.value = true;
        sprintPct.value = 62;
    }

    if (effect === 'meeting') {
        meeting.value = { title: 'Sprint review', when: 'Thu 21 Aug · 16:00' };
    }
}

async function playScene(index: number): Promise<void> {
    active.value = index;
    resetBoard();
    streamed.value = '';
    phase.value = 'typing';

    await typeOut(scenes[index].command);
    if (cancelled) return;

    phase.value = 'thinking';
    await wait(620);
    if (cancelled) return;

    phase.value = 'replying';
    await streamOut(scenes[index].reply);
    if (cancelled) return;

    phase.value = 'confirming';
    await wait(520);
    if (cancelled) return;

    applyEffect(scenes[index].effect);
    phase.value = 'settled';
}

async function loop(): Promise<void> {
    while (!cancelled) {
        await playScene(active.value);
        if (cancelled) return;

        await wait(3200);
        if (cancelled) return;

        active.value = (active.value + 1) % scenes.length;
    }
}

/** Clicking a chip takes the demo straight to that scene. */
function jumpTo(index: number): void {
    cancelled = true;
    timers.forEach(clearTimeout);
    timers = [];

    window.setTimeout(() => {
        cancelled = false;
        active.value = index;
        void loop();
    }, 40);
}

/* Nav */
const scrolled = ref(false);
const menuOpen = ref(false);

function onScroll(): void {
    scrolled.value = window.scrollY > 12;
}

/* Reveal-on-scroll, one per section. */
let observer: IntersectionObserver | null = null;

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

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
    window.removeEventListener('scroll', onScroll);
    observer?.disconnect();
});

const navLinks = [
    { label: 'How it works', href: '#how' },
    { label: "What's inside", href: '#inside' },
    { label: 'For agencies', href: '#agencies' },
];

const boardColumns = computed(() => [
    { name: 'To Do', items: todo.value },
    { name: 'In Progress', items: doing.value },
    { name: 'Done', items: done.value },
]);

const pillars = [
    {
        t: 'It asks before it acts.',
        d: 'Anything that creates, changes or deletes shows you a confirmation card first. Nothing is written on a guess.',
    },
    {
        t: "It can't outrank you.",
        d: "The assistant is only handed the tools your role allows. Ask for something you're not permitted to do and there's no tool there to call.",
    },
    {
        t: 'It leaves a trail.',
        d: 'Workspace and project activity is written to an audit log you can search.',
    },
];

const clientCapabilities = ['View project board & sprints', 'Comment on tasks', 'Request tasks', 'Close tasks — never reopen them', 'View meetings'];

const commandGroups = [
    { group: 'Workspace', items: ['Create a workspace', 'Invite someone', 'Who is in this workspace'] },
    { group: 'Projects', items: ['List projects', 'Create a project', 'Add someone to a project'] },
    { group: 'Tasks', items: ['Find tasks', 'Create a task', 'Update a task', 'Comment on a task', 'Delete a task'] },
    { group: 'Sprints', items: ['Plan, start or close a sprint', 'Sprint status'] },
    { group: 'Meetings', items: ['Schedule a meeting', 'List meetings', 'Change a meeting', 'Cancel a meeting'] },
    { group: 'Insights', items: ['How are we doing'] },
    { group: 'Learn', items: ['Teach me how to use SprintSync'] },
];
</script>

<template>
    <Head title="SprintSync — run your sprint by saying so" />

    <div class="ss">
        <div class="mx-auto max-w-[1320px] px-3 py-3 sm:px-4 sm:py-4">
            <!-- ==================== HERO SHELL ==================== -->
            <section class="relative overflow-hidden rounded-[28px] bg-[var(--ss-lavender)] p-4 sm:rounded-[40px] sm:p-6 lg:p-8">
                <div class="ss-grain" aria-hidden="true"></div>

                <!-- nav, inside the card -->
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
                            class="group flex items-center gap-2 rounded-full bg-[var(--ss-ink)] py-2.5 pr-2.5 pl-5 text-[14px] font-bold text-white transition-transform duration-200 hover:-translate-y-0.5"
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
                </div>

                <!-- hero body -->
                <div class="relative mt-8 grid grid-cols-1 items-stretch gap-5 lg:mt-4 lg:grid-cols-12 lg:gap-6">
                    <!-- the assistant, doing it -->
                    <div class="order-2 lg:order-1 lg:col-span-5">
                        <div class="ss-demo flex flex-col rounded-[24px] bg-[var(--ss-ink)] p-5">
                            <div class="flex h-8 shrink-0 items-center justify-between">
                                <span class="text-[11px] font-extrabold tracking-[0.16em] text-white/40 uppercase">Live preview</span>
                                <span class="flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white/70">
                                    <span class="size-1.5 rounded-full bg-[var(--ss-lime)]"></span>
                                    scripted
                                </span>
                            </div>

                            <!-- composer: fixed height, so typing never moves the page -->
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

                            <!-- reply: fixed height -->
                            <div class="mt-4 h-[96px] shrink-0 overflow-hidden sm:h-[80px]">
                                <div v-if="phase === 'thinking'" class="flex items-center gap-2 px-1 pt-1">
                                    <span class="ss-dot"></span><span class="ss-dot ss-dot-2"></span><span class="ss-dot ss-dot-3"></span>
                                    <span class="ml-1 text-[12px] font-semibold text-white/40">SprintSync is working</span>
                                </div>
                                <p v-else class="px-1 text-[14.5px] leading-[1.5] font-medium text-white/90">{{ streamed }}</p>
                            </div>

                            <!-- outcome slot: reserved for the tallest card, so nothing jumps -->
                            <div class="mt-3 h-[196px] shrink-0">
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
                                        <span class="rounded-full bg-[var(--ss-lime)] px-4 py-1.5 text-[12px] font-extrabold text-[var(--ss-ink)]">
                                            Confirm
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-else-if="showDenied"
                                    class="ss-pop rounded-2xl bg-[rgba(251,113,133,0.10)] p-4 ring-1 ring-[var(--ss-rose)]/40"
                                >
                                    <p class="text-[12.5px] leading-relaxed font-bold text-[var(--ss-rose)]">
                                        Blocked by your permissions — nothing was changed.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- headline -->
                    <div class="order-1 flex flex-col justify-center lg:order-2 lg:col-span-7 lg:pl-4">
                        <span class="text-[12px] font-extrabold tracking-[0.16em] text-[var(--ss-ink)]/45 uppercase">
                            [ AI-native sprint management ]
                        </span>

                        <h1 class="mt-5 text-[clamp(2.5rem,5.6vw,4.75rem)] leading-[0.95] font-extrabold tracking-[-0.035em] text-[var(--ss-ink)]">
                            Run your
                            <span class="ss-glyph"><Kanban class="size-[0.62em] text-[var(--ss-lime)]" :stroke-width="2.5" /></span>
                            sprint by <span class="ss-mark">saying so.</span>
                        </h1>

                        <p class="mt-6 max-w-[44ch] text-[clamp(0.98rem,1.2vw,1.15rem)] leading-[1.55] font-medium text-[var(--ss-ink)]/60">
                            SprintSync turns one sentence into a task, a sprint, a meeting or a report — inside a permission model that keeps the AI
                            in its lane.
                        </p>

                        <div class="mt-8 flex flex-wrap items-center gap-3">
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
                                href="#inside"
                                class="group inline-flex items-center gap-3 rounded-full bg-white py-4 pr-7 pl-3 text-[15px] font-bold text-[var(--ss-ink)] transition-transform duration-200 hover:-translate-y-0.5"
                            >
                                <span class="grid size-8 place-items-center rounded-full bg-[var(--ss-ink)] text-white">
                                    <Play class="size-3.5 fill-current" />
                                </span>
                                Watch it work
                            </a>
                        </div>

                        <div class="mt-8 flex flex-wrap gap-2">
                            <button
                                v-for="(item, index) in scenes"
                                :key="item.id"
                                type="button"
                                class="rounded-full border px-4 py-2 text-[12.5px] font-bold transition-all duration-200 hover:-translate-y-0.5"
                                :class="
                                    active === index
                                        ? 'border-transparent bg-[var(--ss-ink)] text-white'
                                        : 'border-[rgba(11,11,15,0.14)] text-[var(--ss-ink)]/55 hover:border-[var(--ss-ink)] hover:text-[var(--ss-ink)]'
                                "
                                @click="jumpTo(index)"
                            >
                                {{ item.chip }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ==================== BENTO ==================== -->
            <div class="mt-3 grid grid-cols-1 gap-3 sm:mt-4 sm:gap-4 lg:grid-cols-12">
                <!-- board -->
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

                    <div class="mt-4 grid flex-1 grid-cols-3 gap-2.5">
                        <div v-for="col in boardColumns" :key="col.name">
                            <p class="mb-2 flex items-center gap-1.5 text-[10px] font-extrabold tracking-wider text-[var(--ss-ink)]/35 uppercase">
                                {{ col.name }}
                                <span class="rounded-full bg-[var(--ss-paper)] px-1.5 py-0.5">{{ col.items.length }}</span>
                            </p>
                            <div class="h-[152px] space-y-2 overflow-hidden">
                                <div
                                    v-for="card in col.items"
                                    :key="card"
                                    class="ss-pop rounded-xl bg-[var(--ss-paper)] p-2.5 text-[11px] leading-snug font-bold text-[var(--ss-ink)]"
                                >
                                    {{ card }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- sprint health -->
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

                <!-- commands -->
                <div class="ss-bento flex flex-col justify-between rounded-[28px] bg-[var(--ss-ink)] p-5 sm:p-6 lg:col-span-4">
                    <span class="h-7 shrink-0 text-[11px] font-extrabold tracking-[0.14em] text-white/40 uppercase">[ Ask for anything ]</span>
                    <div>
                        <p class="text-[clamp(2.75rem,5vw,3.5rem)] leading-none font-extrabold tracking-tight text-white">19</p>
                        <p class="mt-2 text-[14.5px] leading-snug font-bold text-white/80">
                            real actions the assistant can take — the same ones the buttons call.
                        </p>
                        <a
                            href="#inside"
                            class="mt-4 inline-flex items-center gap-1 text-[13px] font-extrabold text-[var(--ss-lime)] transition-all hover:gap-2"
                        >
                            See the list <ChevronRight class="size-4" :stroke-width="3" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- ==================== TRUST ==================== -->
            <section id="how" data-reveal class="ss-reveal px-2 py-20 sm:px-4 sm:py-28">
                <div class="grid gap-10 md:grid-cols-3">
                    <div v-for="pillar in pillars" :key="pillar.t">
                        <span class="grid size-10 place-items-center rounded-full bg-[var(--ss-lime)]">
                            <Check class="size-5 text-[var(--ss-ink)]" :stroke-width="3" />
                        </span>
                        <h3 class="mt-5 text-[clamp(1.4rem,2vw,1.8rem)] leading-tight font-extrabold tracking-tight text-white">{{ pillar.t }}</h3>
                        <p class="mt-3 text-[15px] leading-relaxed font-medium text-white/50">{{ pillar.d }}</p>
                    </div>
                </div>
            </section>

            <!-- ==================== COMMANDS ==================== -->
            <section id="inside" data-reveal class="ss-reveal">
                <div class="rounded-[28px] bg-white p-6 sm:rounded-[40px] sm:p-12">
                    <span class="text-[12px] font-extrabold tracking-[0.16em] text-[var(--ss-indigo)] uppercase"
                        >[ The whole product, in sentences ]</span
                    >
                    <h2
                        class="mt-4 max-w-[20ch] text-[clamp(2rem,4.4vw,3.5rem)] leading-[1.02] font-extrabold tracking-[-0.03em] text-[var(--ss-ink)]"
                    >
                        Nineteen things you can just ask for.
                    </h2>

                    <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="group in commandGroups" :key="group.group">
                            <p class="text-[11px] font-extrabold tracking-[0.14em] text-[var(--ss-ink)]/35 uppercase">{{ group.group }}</p>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <span
                                    v-for="item in group.items"
                                    :key="item"
                                    class="rounded-full bg-[var(--ss-paper)] px-3 py-1.5 text-[12px] font-bold text-[var(--ss-ink)]/75 transition-colors hover:bg-[var(--ss-lime)] hover:text-[var(--ss-ink)]"
                                >
                                    {{ item }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <p class="mt-10 text-[13px] font-medium text-[var(--ss-ink)]/40">
                        Type <span class="rounded bg-[var(--ss-paper)] px-1.5 py-0.5 font-mono font-bold text-[var(--ss-ink)]/70">/</span> in the app
                        to search all nineteen in plain language. "want to start a new sprint" finds the right one.
                    </p>
                </div>
            </section>

            <!-- ==================== AGENCIES ==================== -->
            <section id="agencies" data-reveal class="ss-reveal mt-3 grid grid-cols-1 items-stretch gap-3 sm:mt-4 sm:gap-4 lg:grid-cols-12">
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

            <!-- ==================== FOOTER ==================== -->
            <footer class="mt-4 flex flex-col items-center justify-between gap-4 px-2 py-8 sm:flex-row sm:px-4">
                <div class="flex items-center gap-2.5">
                    <span class="grid size-8 place-items-center rounded-full bg-[var(--ss-lime)]">
                        <Sparkles class="size-4 text-[var(--ss-ink)]" :stroke-width="2.5" />
                    </span>
                    <span class="text-[14px] font-extrabold text-white">sprintsync</span>
                    <span class="text-[13px] font-semibold text-white/35">AI-native sprint management.</span>
                </div>
                <p class="text-[13px] font-semibold text-white/35">© 2026 SprintSync · Built with Laravel and Claude.</p>
            </footer>
        </div>
    </div>
</template>

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

    background: var(--ss-ink);
    color: var(--ss-ink);
    min-height: 100vh;
}

/*
 * Every region the demo writes into has a reserved height. The typed command,
 * the streamed reply and the confirmation card all change size as the loop
 * runs, and without this the whole page would shift under the reader.
 */
.ss-demo {
    height: 560px;
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

@media (prefers-reduced-motion: reduce) {
    .ss-mark::after {
        animation: none;
        transform: scaleX(1);
    }

    .ss-caret,
    .ss-dot,
    .ss-pop {
        animation: none;
    }

    .ss-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}
</style>
