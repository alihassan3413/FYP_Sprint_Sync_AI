<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import {
    ArrowRight,
    ArrowUpRight,
    Bot,
    Boxes,
    Check,
    ChevronRight,
    Code2,
    FileText,
    Github,
    Kanban,
    KeyRound,
    Layers,
    LayoutDashboard,
    LineChart,
    MessageSquare,
    Minus,
    Plus,
    Server,
    Sparkles,
    Star,
    Zap,
    X,
} from 'lucide-vue-next';

// FAQ accordion state
const openFaq = ref<number | null>(0);
const toggleFaq = (i: number) => (openFaq.value = openFaq.value === i ? null : i);

// Pricing toggle
const billingYearly = ref(true);

// Animated stats counter
const stats = ref([
    { label: 'Avg. Issue Triage Time', value: '0.4s', sub: 'AI-classified instantly' },
    { label: 'Setup Time', value: '< 5min', sub: 'vs Jira’s 2 weeks' },
    { label: 'Tools Replaced', value: '6+', sub: 'kanban, docs, chat, sprints, wiki, time' },
    { label: 'Self-Hosting', value: '100%', sub: 'your data, your servers' },
]);

// Page-load orchestration
const mounted = ref(false);
onMounted(() => {
    mounted.value = true;
});

// FAQ data
const faqs = [
    {
        q: 'How is Sprint Sync different from Jira?',
        a: 'Jira buries you in configuration. Sprint Sync ships with sensible defaults — boards, sprints, and AI triage work in 5 minutes flat. No Scrum Master required, no certification, no part-time admin. And it’s self-hostable, so your roadmap never lives on someone else’s server.',
    },
    {
        q: 'Why not just use Linear?',
        a: 'Linear is fast — we love it — but it’s built for engineers only. Your PMs, designers, and ops folks bounce off the mental model. Sprint Sync gives engineering Linear-grade speed AND gives the rest of the org a workspace they’ll actually open. One tool, no tribal silos.',
    },
    {
        q: 'Is the AI actually useful or just a marketing checkbox?',
        a: 'Real work, no theatre. Sprint Sync AI auto-triages incoming tickets, drafts release notes from your sprint, summarizes long comment threads into 3 bullets, and turns voice notes into structured issues. It runs on your infra if you self-host — your data stays yours.',
    },
    {
        q: 'What does "all-in-one" actually mean here?',
        a: 'Kanban boards + sprint planning + docs/wiki + threaded comments + time tracking + roadmaps — all native, not bolted on. You stop paying for Notion + Slack + Jira + Toggl + Confluence. One workspace, one bill, one source of truth.',
    },
    {
        q: 'Can I self-host it?',
        a: 'Yes. Sprint Sync ships with a Docker compose file and detailed self-host docs. Run it on your own VPS, in your VPC, or air-gapped on a laptop. Source code is open. Your roadmap, your servers, your rules.',
    },
    {
        q: 'How is the pricing structured?',
        a: 'Flat per-seat pricing, no enterprise upsell traps. Free tier covers up to 10 users — same as Jira but without the configuration nightmare. Paid plans unlock advanced AI agents, SSO, and unlimited automations. No "Premium" / "Premium+" / "Premium Plus" ladder.',
    },
];

// Comparison data
const compareRows = [
    { label: 'Setup time', sprintsync: '< 5 minutes', jira: '1-2 weeks', linear: '~10 minutes', clickup: '2-3 weeks' },
    { label: 'Built for non-devs', sprintsync: true, jira: 'sort of', linear: false, clickup: true },
    { label: 'Native AI triage', sprintsync: true, jira: 'add-on', linear: 'partial', clickup: 'add-on' },
    { label: 'Self-hostable', sprintsync: true, jira: false, linear: false, clickup: false },
    { label: 'All-in-one (docs + chat + boards)', sprintsync: true, jira: false, linear: false, clickup: 'bloated' },
    { label: 'Keyboard-first UX', sprintsync: true, jira: false, linear: true, clickup: false },
    { label: 'Source-available', sprintsync: true, jira: false, linear: false, clickup: false },
    { label: 'Configuration debt', sprintsync: 'none', jira: 'crushing', linear: 'low', clickup: 'high' },
];

// Pricing tiers
const tiers = [
    {
        name: 'Solo',
        tagline: 'For indie hackers & freelancers',
        priceMonthly: 0,
        priceYearly: 0,
        cta: 'Start free',
        highlighted: false,
        features: ['Up to 10 users', 'Unlimited projects', 'Kanban + sprints', 'Basic AI assist (50/mo)', 'Community support'],
    },
    {
        name: 'Team',
        tagline: 'For growing teams that ship',
        priceMonthly: 9,
        priceYearly: 7,
        cta: 'Start 14-day trial',
        highlighted: true,
        features: ['Unlimited users', 'AI auto-triage & summaries', 'Docs + Wiki', 'Roadmaps + Goals', 'SSO + advanced permissions', 'Priority support'],
    },
    {
        name: 'Self-Hosted',
        tagline: 'For privacy-first orgs',
        priceMonthly: 19,
        priceYearly: 15,
        cta: 'Get the binary',
        highlighted: false,
        features: ['Everything in Team', 'Run on your infra', 'Air-gapped deployment OK', 'Source code access', 'Custom AI model endpoints', 'Dedicated Slack channel'],
    },
];
</script>

<template>
    <Head title="Sprint Sync — The All-in-One Workspace That Doesn't Suck">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link
            href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div class="min-h-screen bg-[#fafaf7] font-sans text-black antialiased selection:bg-lime-300 selection:text-black">
        <div class="overflow-hidden border-b-[3px] border-black bg-black text-white">
            <div class="animate-marquee flex whitespace-nowrap py-2 text-sm font-bold uppercase tracking-widest">
                <span class="mx-6">★ SHIP FASTER</span>
                <span class="mx-6">★ NO ADMIN HEADACHES</span>
                <span class="mx-6">★ SELF-HOSTABLE</span>
                <span class="mx-6">★ AI THAT ACTUALLY WORKS</span>
                <span class="mx-6">★ KANBAN + DOCS + CHAT + SPRINTS</span>
                <span class="mx-6">★ NEW GEN TOOL</span>
                <span class="mx-6">★ SHIP FASTER</span>
                <span class="mx-6">★ NO ADMIN HEADACHES</span>
                <span class="mx-6">★ SELF-HOSTABLE</span>
                <span class="mx-6">★ AI THAT ACTUALLY WORKS</span>
                <span class="mx-6">★ KANBAN + DOCS + CHAT + SPRINTS</span>
                <span class="mx-6">★ NEW GEN TOOL</span>
            </div>
        </div>


        <header class="sticky top-0 z-40 border-b-[3px] border-black bg-[#fafaf7]/90 backdrop-blur">
            <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 md:px-8">
                <Link :href="route('home')" class="group flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 items-center justify-center border-[3px] border-black bg-lime-300 shadow-[4px_4px_0_0_#000] transition-all group-hover:translate-x-[-2px] group-hover:translate-y-[-2px] group-hover:shadow-[6px_6px_0_0_#000]"
                    >
                        <Boxes class="h-5 w-5" :stroke-width="3" />
                    </div>
                    <span class="text-xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">Sprint Sync</span>
                </Link>

                <div class="hidden items-center gap-8 lg:flex">
                    <a href="#features" class="text-sm font-bold uppercase tracking-wider hover:underline">Features</a>
                    <a href="#compare" class="text-sm font-bold uppercase tracking-wider hover:underline">Compare</a>
                    <a href="#pricing" class="text-sm font-bold uppercase tracking-wider hover:underline">Pricing</a>
                    <a href="#faq" class="text-sm font-bold uppercase tracking-wider hover:underline">FAQ</a>
                </div>

                <div class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth?.user"
                        :href="route('dashboard')"
                        class="border-[3px] border-black bg-white px-5 py-2.5 text-sm font-bold uppercase tracking-wider shadow-[4px_4px_0_0_#000] transition-all hover:translate-x-[-2px] hover:translate-y-[-2px] hover:shadow-[6px_6px_0_0_#000] active:translate-x-0 active:translate-y-0 active:shadow-[2px_2px_0_0_#000]"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link :href="route('login')" class="hidden text-sm font-bold uppercase tracking-wider hover:underline sm:inline">Log in</Link>
                        <Link
                            :href="route('register')"
                            class="border-[3px] border-black bg-lime-300 px-5 py-2.5 text-sm font-bold uppercase tracking-wider shadow-[4px_4px_0_0_#000] transition-all hover:translate-x-[-2px] hover:translate-y-[-2px] hover:shadow-[6px_6px_0_0_#000] active:translate-x-0 active:translate-y-0 active:shadow-[2px_2px_0_0_#000]"
                        >
                            Get Started
                        </Link>
                    </template>
                </div>
            </nav>
        </header>


        <section class="relative overflow-hidden border-b-[3px] border-black">

            <div
                class="absolute inset-0 opacity-[0.04]"
                style="
                    background-image:
                        linear-gradient(to right, #000 1px, transparent 1px),
                        linear-gradient(to bottom, #000 1px, transparent 1px);
                    background-size: 40px 40px;
                "
            ></div>

            <div class="relative mx-auto max-w-7xl px-4 py-16 md:px-8 md:py-24 lg:py-32">
                <div class="grid gap-12 lg:grid-cols-12 lg:gap-8">
                    <!-- Left: headline -->
                    <div class="lg:col-span-7">
                        <!-- Tag pill -->
                        <div
                            class="inline-flex items-center gap-2 border-[3px] border-black bg-white px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]"
                        >
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-lime-500 opacity-75"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-lime-500"></span>
                            </span>
                            v1.0 — Now in public beta
                        </div>

                        <h1
                            class="mt-6 text-5xl leading-[0.95] tracking-tight md:text-7xl lg:text-[5.5rem]"
                            style="font-family: 'Archivo Black', sans-serif"
                        >
                            The workspace
                            <span class="relative inline-block">
                                <span class="relative z-10">that</span>
                            </span>
                            <span class="relative ml-2 inline-block bg-lime-300 px-3 -rotate-2 border-[3px] border-black shadow-[6px_6px_0_0_#000]">
                                ships.
                            </span>
                        </h1>

                        <p class="mt-8 max-w-xl text-lg leading-relaxed text-neutral-700 md:text-xl">
                            Jira is a swamp. Linear is dev-only. ClickUp is a feature graveyard.
                            <strong class="text-black">Sprint Sync is the all-in-one workspace</strong>
                            your engineers, designers, and PMs all actually open every day.
                        </p>

                        <div class="mt-10 flex flex-col gap-4 sm:flex-row">
                            <Link
                                :href="route('register')"
                                class="group inline-flex items-center justify-center gap-2 border-[3px] border-black bg-lime-300 px-8 py-4 text-base font-bold uppercase tracking-wider shadow-[6px_6px_0_0_#000] transition-all hover:translate-x-[-2px] hover:translate-y-[-2px] hover:shadow-[8px_8px_0_0_#000] active:translate-x-0 active:translate-y-0 active:shadow-[3px_3px_0_0_#000]"
                            >
                                Start Free — No Card
                                <ArrowRight class="h-5 w-5 transition-transform group-hover:translate-x-1" :stroke-width="3" />
                            </Link>
                            <a
                                href="#compare"
                                class="inline-flex items-center justify-center gap-2 border-[3px] border-black bg-white px-8 py-4 text-base font-bold uppercase tracking-wider shadow-[6px_6px_0_0_#000] transition-all hover:translate-x-[-2px] hover:translate-y-[-2px] hover:shadow-[8px_8px_0_0_#000]"
                            >
                                Why we beat Jira
                                <ChevronRight class="h-5 w-5" :stroke-width="3" />
                            </a>
                        </div>

                        <!-- Trust row -->
                        <div class="mt-12 flex flex-wrap items-center gap-6 text-xs font-bold uppercase tracking-widest text-neutral-600">
                            <span class="flex items-center gap-1.5"><Check class="h-4 w-4" :stroke-width="3" /> 14-day free trial</span>
                            <span class="flex items-center gap-1.5"><Check class="h-4 w-4" :stroke-width="3" /> Self-hostable</span>
                            <span class="flex items-center gap-1.5"><Check class="h-4 w-4" :stroke-width="3" /> Open source core</span>
                        </div>
                    </div>

                    <!-- Right: product preview mock -->
                    <div class="lg:col-span-5">
                        <div class="relative">
                            <!-- Stacked decorative card -->
                            <div class="absolute inset-0 translate-x-3 translate-y-3 border-[3px] border-black bg-pink-300"></div>
                            <div class="absolute inset-0 translate-x-1.5 translate-y-1.5 border-[3px] border-black bg-lime-300"></div>

                            <!-- Main "screenshot" card -->
                            <div class="relative border-[3px] border-black bg-white p-5 shadow-[10px_10px_0_0_#000]">
                                <!-- Window chrome -->
                                <div class="mb-4 flex items-center gap-2 border-b-2 border-black pb-3">
                                    <div class="h-3 w-3 border-2 border-black bg-red-400"></div>
                                    <div class="h-3 w-3 border-2 border-black bg-yellow-300"></div>
                                    <div class="h-3 w-3 border-2 border-black bg-lime-300"></div>
                                    <span class="ml-3 font-mono text-xs font-bold">sprintsync.app/board</span>
                                </div>

                                <!-- Mock kanban -->
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="space-y-2">
                                        <div class="border-2 border-black bg-neutral-100 px-2 py-1 text-[10px] font-bold uppercase">To Do · 3</div>
                                        <div class="border-2 border-black bg-white p-2 text-xs shadow-[2px_2px_0_0_#000]">
                                            <div class="mb-1 font-bold">Auth flow</div>
                                            <div class="text-[10px] text-neutral-500">SS-12</div>
                                        </div>
                                        <div class="border-2 border-black bg-white p-2 text-xs shadow-[2px_2px_0_0_#000]">
                                            <div class="mb-1 font-bold">OAuth setup</div>
                                            <div class="text-[10px] text-neutral-500">SS-13</div>
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="border-2 border-black bg-yellow-200 px-2 py-1 text-[10px] font-bold uppercase">Doing · 2</div>
                                        <div class="border-2 border-black bg-white p-2 text-xs shadow-[2px_2px_0_0_#000]">
                                            <div class="mb-1 font-bold">Kanban DnD</div>
                                            <div class="text-[10px] text-neutral-500">SS-14</div>
                                            <div class="mt-1.5 inline-flex items-center gap-1 border border-black bg-pink-200 px-1 text-[9px] font-bold">
                                                <Sparkles class="h-2 w-2" :stroke-width="3" /> AI
                                            </div>
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="border-2 border-black bg-lime-300 px-2 py-1 text-[10px] font-bold uppercase">Done · 8</div>
                                        <div class="border-2 border-black bg-white p-2 text-xs shadow-[2px_2px_0_0_#000]">
                                            <div class="mb-1 font-bold line-through opacity-60">Schema</div>
                                            <div class="text-[10px] text-neutral-500">SS-11</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- AI assist popup -->
                                <div
                                    class="mt-4 flex items-start gap-2 border-[3px] border-black bg-pink-200 p-3 shadow-[3px_3px_0_0_#000]"
                                >
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center border-2 border-black bg-black">
                                        <Sparkles class="h-4 w-4 text-lime-300" :stroke-width="3" />
                                    </div>
                                    <div class="text-xs">
                                        <div class="font-bold">AI auto-triaged 4 tickets</div>
                                        <div class="mt-0.5 text-neutral-700">Assigned to @ali, @sara · saved 12min</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats row -->
                <div class="mt-20 grid gap-0 border-[3px] border-black bg-white shadow-[8px_8px_0_0_#000] sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(s, i) in stats"
                        :key="s.label"
                        class="border-black p-6 transition-colors hover:bg-lime-50"
                        :class="[
                            i !== 0 && 'border-t-[3px] sm:border-t-0',
                            i % 2 !== 0 && 'sm:border-l-[3px]',
                            i >= 2 && 'sm:border-t-[3px] lg:border-t-0',
                            i > 0 && 'lg:border-l-[3px] lg:border-t-0',
                        ]"
                    >
                        <div class="text-4xl font-black tracking-tight md:text-5xl" style="font-family: 'Archivo Black', sans-serif">
                            {{ s.value }}
                        </div>
                        <div class="mt-2 text-xs font-bold uppercase tracking-widest text-neutral-700">{{ s.label }}</div>
                        <div class="mt-1 text-xs text-neutral-500">{{ s.sub }}</div>
                    </div>
                </div>
            </div>
        </section>


        <section class="border-b-[3px] border-black bg-black py-20 text-white md:py-28">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 border-[3px] border-lime-300 bg-black px-4 py-1.5 text-xs font-bold uppercase tracking-widest text-lime-300">
                        <Zap class="h-3 w-3" :stroke-width="3" /> The problem
                    </div>
                    <h2 class="mt-6 text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        Project tools have a <span class="bg-lime-300 px-2 text-black">brand problem.</span>
                    </h2>
                    <p class="mt-6 text-lg text-neutral-300 md:text-xl">
                        Every sprint planning meeting starts with someone complaining about the tool. We got tired of it. So we built the one we wished existed.
                    </p>
                </div>

                <div class="mt-16 grid gap-6 md:grid-cols-3">
                    <!-- Jira card -->
                    <div class="group border-[3px] border-white bg-neutral-900 p-7 transition-all hover:bg-red-950">
                        <div class="mb-5 inline-flex h-12 w-12 items-center justify-center border-[3px] border-white bg-red-500">
                            <X class="h-6 w-6" :stroke-width="3" />
                        </div>
                        <h3 class="text-2xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">Jira</h3>
                        <p class="mt-3 text-sm font-bold uppercase tracking-wider text-red-300">The configuration swamp</p>
                        <p class="mt-4 text-neutral-300">
                            Two weeks of setup. A part-time admin. Custom fields no one remembers adding. By month six, your workflow looks like a Visio diagram from 2008.
                        </p>
                    </div>

                    <!-- Linear card -->
                    <div class="group border-[3px] border-white bg-neutral-900 p-7 transition-all hover:bg-yellow-950">
                        <div class="mb-5 inline-flex h-12 w-12 items-center justify-center border-[3px] border-white bg-yellow-400">
                            <Minus class="h-6 w-6 text-black" :stroke-width="3" />
                        </div>
                        <h3 class="text-2xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">Linear</h3>
                        <p class="mt-3 text-sm font-bold uppercase tracking-wider text-yellow-300">The dev-only club</p>
                        <p class="mt-4 text-neutral-300">
                            Fast — beautiful, even. But your PM bounces off the keyboard shortcuts, your designer can’t find the docs, and ops gets locked out. Tribal knowledge silos.
                        </p>
                    </div>

                    <!-- ClickUp card -->
                    <div class="group border-[3px] border-white bg-neutral-900 p-7 transition-all hover:bg-orange-950">
                        <div class="mb-5 inline-flex h-12 w-12 items-center justify-center border-[3px] border-white bg-orange-400">
                            <Layers class="h-6 w-6 text-black" :stroke-width="3" />
                        </div>
                        <h3 class="text-2xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">ClickUp</h3>
                        <p class="mt-3 text-sm font-bold uppercase tracking-wider text-orange-300">Feature graveyard</p>
                        <p class="mt-4 text-neutral-300">
                            17 view types, 40 custom field options, 12 automation triggers. Half are half-baked. New hires need three weeks to find anything. Slow on bad days.
                        </p>
                    </div>
                </div>

                <!-- "And then there's us" -->
                <div class="relative mt-16">
                    <div class="absolute inset-0 translate-x-2 translate-y-2 bg-pink-400"></div>
                    <div class="relative border-[3px] border-white bg-lime-300 p-8 text-black md:p-12">
                        <div class="flex items-start gap-6 md:items-center">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center border-[3px] border-black bg-black md:h-20 md:w-20">
                                <Boxes class="h-8 w-8 text-lime-300 md:h-10 md:w-10" :stroke-width="3" />
                            </div>
                            <div>
                                <p class="text-sm font-bold uppercase tracking-widest">And then there’s us</p>
                                <h3 class="mt-2 text-3xl tracking-tight md:text-5xl" style="font-family: 'Archivo Black', sans-serif">
                                    Sprint Sync is the workspace your whole team will actually use.
                                </h3>
                                <p class="mt-4 max-w-2xl text-base md:text-lg">
                                    Linear-grade speed for engineers. Notion-style docs for PMs. Figma-friendly comments for designers. AI that does the boring work. Self-host it if you want.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        <section id="features" class="border-b-[3px] border-black py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 border-[3px] border-black bg-pink-300 px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]">
                        <Sparkles class="h-3 w-3" :stroke-width="3" /> What you get
                    </div>
                    <h2 class="mt-6 text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        Everything your team needs.<br />
                        <span class="bg-black px-2 text-lime-300">Nothing it doesn't.</span>
                    </h2>
                </div>

                <!-- Feature 1: Big AI feature -->
                <div class="mt-16 grid gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-7">
                        <div class="relative h-full">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-pink-300"></div>
                            <div class="relative h-full border-[3px] border-black bg-white p-8 shadow-[6px_6px_0_0_#000] md:p-10">
                                <div class="flex h-14 w-14 items-center justify-center border-[3px] border-black bg-black">
                                    <Sparkles class="h-7 w-7 text-lime-300" :stroke-width="3" />
                                </div>
                                <h3 class="mt-6 text-3xl font-black tracking-tight md:text-4xl" style="font-family: 'Archivo Black', sans-serif">
                                    AI that does the boring work.
                                </h3>
                                <p class="mt-4 text-neutral-700 md:text-lg">
                                    Drop a bug report into the queue — Sprint Sync AI auto-classifies it, tags it, assigns it to the right engineer, and even drafts the first reply.
                                </p>

                                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                                    <div class="border-2 border-black bg-neutral-50 p-3">
                                        <div class="text-xs font-bold uppercase tracking-wider text-neutral-500">Auto-Triage</div>
                                        <div class="mt-1 text-sm font-bold">99% accuracy on labels</div>
                                    </div>
                                    <div class="border-2 border-black bg-neutral-50 p-3">
                                        <div class="text-xs font-bold uppercase tracking-wider text-neutral-500">Sprint Summary</div>
                                        <div class="mt-1 text-sm font-bold">3-bullet TL;DR by Friday 4pm</div>
                                    </div>
                                    <div class="border-2 border-black bg-neutral-50 p-3">
                                        <div class="text-xs font-bold uppercase tracking-wider text-neutral-500">Voice → Issue</div>
                                        <div class="mt-1 text-sm font-bold">Speak. We structure.</div>
                                    </div>
                                    <div class="border-2 border-black bg-neutral-50 p-3">
                                        <div class="text-xs font-bold uppercase tracking-wider text-neutral-500">Release Notes</div>
                                        <div class="mt-1 text-sm font-bold">Drafted from your sprint</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-5">
                        <div class="relative h-full">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-lime-300"></div>
                            <div class="relative flex h-full flex-col border-[3px] border-black bg-black p-8 text-white shadow-[6px_6px_0_0_#000] md:p-10">
                                <div class="flex h-14 w-14 items-center justify-center border-[3px] border-white bg-lime-300">
                                    <Layers class="h-7 w-7 text-black" :stroke-width="3" />
                                </div>
                                <h3 class="mt-6 text-3xl font-black tracking-tight md:text-4xl" style="font-family: 'Archivo Black', sans-serif">
                                    One tool. Six tools’ worth of work.
                                </h3>
                                <p class="mt-4 text-neutral-300">
                                    Stop paying for Notion + Slack + Jira + Toggl + Confluence. We bundled it.
                                </p>
                                <ul class="mt-6 space-y-3">
                                    <li class="flex items-center gap-3 text-sm">
                                        <Kanban class="h-5 w-5 text-lime-300" :stroke-width="3" /> Kanban + Sprints
                                    </li>
                                    <li class="flex items-center gap-3 text-sm">
                                        <FileText class="h-5 w-5 text-lime-300" :stroke-width="3" /> Docs & Wiki
                                    </li>
                                    <li class="flex items-center gap-3 text-sm">
                                        <MessageSquare class="h-5 w-5 text-lime-300" :stroke-width="3" /> Threaded comments
                                    </li>
                                    <li class="flex items-center gap-3 text-sm">
                                        <LineChart class="h-5 w-5 text-lime-300" :stroke-width="3" /> Roadmaps & goals
                                    </li>
                                    <li class="flex items-center gap-3 text-sm">
                                        <LayoutDashboard class="h-5 w-5 text-lime-300" :stroke-width="3" /> Custom dashboards
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="lg:col-span-4">
                        <div class="relative h-full">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-cyan-300"></div>
                            <div class="relative flex h-full flex-col border-[3px] border-black bg-white p-7 shadow-[6px_6px_0_0_#000]">
                                <div class="flex h-12 w-12 items-center justify-center border-[3px] border-black bg-cyan-300">
                                    <Server class="h-6 w-6" :stroke-width="3" />
                                </div>
                                <h3 class="mt-5 text-2xl font-black tracking-tight" style="font-family: 'Archivo Black', sans-serif">
                                    Self-host. Sleep well.
                                </h3>
                                <p class="mt-3 text-sm text-neutral-700">
                                    Docker compose, run it on your VPS or air-gapped. Your roadmap never lives on someone else’s server.
                                </p>
                                <code class="mt-5 block border-2 border-black bg-neutral-900 p-3 font-mono text-xs text-lime-300">
                                    $ docker compose up -d<br />
                                    <span class="text-neutral-500"># sprint-sync running on :8080</span>
                                </code>
                            </div>
                        </div>
                    </div>

                    <!-- Feature 4: Speed -->
                    <div class="lg:col-span-4">
                        <div class="relative h-full">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-yellow-300"></div>
                            <div class="relative flex h-full flex-col border-[3px] border-black bg-white p-7 shadow-[6px_6px_0_0_#000]">
                                <div class="flex h-12 w-12 items-center justify-center border-[3px] border-black bg-yellow-300">
                                    <Zap class="h-6 w-6" :stroke-width="3" />
                                </div>
                                <h3 class="mt-5 text-2xl font-black tracking-tight" style="font-family: 'Archivo Black', sans-serif">
                                    Keyboard-first. Always.
                                </h3>
                                <p class="mt-3 text-sm text-neutral-700">
                                    Every action in 1-2 keystrokes. Command palette opens with <kbd class="border-2 border-black bg-neutral-100 px-1 font-mono text-[10px] font-bold">⌘K</kbd>. Mouse optional.
                                </p>
                                <div class="mt-5 flex flex-wrap gap-1.5">
                                    <kbd class="border-2 border-black bg-neutral-100 px-2 py-0.5 font-mono text-[10px] font-bold">⌘K</kbd>
                                    <kbd class="border-2 border-black bg-neutral-100 px-2 py-0.5 font-mono text-[10px] font-bold">C</kbd>
                                    <kbd class="border-2 border-black bg-neutral-100 px-2 py-0.5 font-mono text-[10px] font-bold">⌘↵</kbd>
                                    <kbd class="border-2 border-black bg-neutral-100 px-2 py-0.5 font-mono text-[10px] font-bold">G→I</kbd>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Feature 5: Open source -->
                    <div class="lg:col-span-4">
                        <div class="relative h-full">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-orange-300"></div>
                            <div class="relative flex h-full flex-col border-[3px] border-black bg-white p-7 shadow-[6px_6px_0_0_#000]">
                                <div class="flex h-12 w-12 items-center justify-center border-[3px] border-black bg-orange-300">
                                    <Github class="h-6 w-6" :stroke-width="3" />
                                </div>
                                <h3 class="mt-5 text-2xl font-black tracking-tight" style="font-family: 'Archivo Black', sans-serif">
                                    Source-available.
                                </h3>
                                <p class="mt-3 text-sm text-neutral-700">
                                    Read the code. Audit the AI. Fork it. Submit PRs. Lock-in is for vendors who don’t trust themselves.
                                </p>
                                <a
                                    href="https://github.com/alihassan3413/FYP_Sprint_Sync_AI"
                                    class="mt-5 inline-flex items-center gap-2 self-start border-2 border-black bg-black px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-white transition-all hover:bg-orange-300 hover:text-black"
                                >
                                    <Star class="h-3 w-3" :stroke-width="3" /> Star on GitHub
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        <section id="compare" class="border-b-[3px] border-black bg-pink-200 py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center gap-2 border-[3px] border-black bg-white px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]">
                        <KeyRound class="h-3 w-3" :stroke-width="3" /> Receipts
                    </div>
                    <h2 class="mt-6 text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        Side by side.<br />Honestly.
                    </h2>
                    <p class="mt-4 text-lg text-neutral-800">No marketing fluff. Here's how Sprint Sync stacks up against the big three.</p>
                </div>

                <div class="relative mt-12">
                    <div class="absolute inset-0 translate-x-2 translate-y-2 bg-black"></div>
                    <div class="relative overflow-x-auto border-[3px] border-black bg-white">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b-[3px] border-black bg-black text-white">
                                <tr>
                                    <th class="p-4 font-bold uppercase tracking-wider md:p-5">Feature</th>
                                    <th class="border-l-[3px] border-white bg-lime-300 p-4 font-black uppercase tracking-wider text-black md:p-5">
                                        Sprint Sync
                                    </th>
                                    <th class="border-l-[3px] border-white p-4 font-bold uppercase tracking-wider md:p-5">Jira</th>
                                    <th class="border-l-[3px] border-white p-4 font-bold uppercase tracking-wider md:p-5">Linear</th>
                                    <th class="border-l-[3px] border-white p-4 font-bold uppercase tracking-wider md:p-5">ClickUp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in compareRows" :key="row.label" :class="i % 2 === 0 ? 'bg-white' : 'bg-neutral-50'">
                                    <td class="border-t-2 border-black p-4 font-bold md:p-5">{{ row.label }}</td>
                                    <td class="border-l-2 border-t-2 border-black bg-lime-100 p-4 font-bold md:p-5">
                                        <span v-if="row.sprintsync === true" class="inline-flex items-center gap-1.5">
                                            <Check class="h-4 w-4" :stroke-width="3" /> Yes
                                        </span>
                                        <span v-else-if="row.sprintsync === false" class="inline-flex items-center gap-1.5 text-neutral-400">
                                            <X class="h-4 w-4" :stroke-width="3" /> No
                                        </span>
                                        <span v-else>{{ row.sprintsync }}</span>
                                    </td>
                                    <td class="border-l-2 border-t-2 border-black p-4 md:p-5">
                                        <span v-if="row.jira === true" class="inline-flex items-center gap-1.5">
                                            <Check class="h-4 w-4" :stroke-width="3" /> Yes
                                        </span>
                                        <span v-else-if="row.jira === false" class="inline-flex items-center gap-1.5 text-neutral-400">
                                            <X class="h-4 w-4" :stroke-width="3" /> No
                                        </span>
                                        <span v-else>{{ row.jira }}</span>
                                    </td>
                                    <td class="border-l-2 border-t-2 border-black p-4 md:p-5">
                                        <span v-if="row.linear === true" class="inline-flex items-center gap-1.5">
                                            <Check class="h-4 w-4" :stroke-width="3" /> Yes
                                        </span>
                                        <span v-else-if="row.linear === false" class="inline-flex items-center gap-1.5 text-neutral-400">
                                            <X class="h-4 w-4" :stroke-width="3" /> No
                                        </span>
                                        <span v-else>{{ row.linear }}</span>
                                    </td>
                                    <td class="border-l-2 border-t-2 border-black p-4 md:p-5">
                                        <span v-if="row.clickup === true" class="inline-flex items-center gap-1.5">
                                            <Check class="h-4 w-4" :stroke-width="3" /> Yes
                                        </span>
                                        <span v-else-if="row.clickup === false" class="inline-flex items-center gap-1.5 text-neutral-400">
                                            <X class="h-4 w-4" :stroke-width="3" /> No
                                        </span>
                                        <span v-else>{{ row.clickup }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b-[3px] border-black py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="text-center">
                    <div class="inline-flex items-center gap-2 border-[3px] border-black bg-yellow-300 px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]">
                        <Star class="h-3 w-3" :stroke-width="3" /> Loved by builders
                    </div>
                    <h2 class="mx-auto mt-6 max-w-3xl text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        People are saying things.
                    </h2>
                </div>

                <div class="mt-16 grid gap-6 md:grid-cols-3">
                    <!-- Testimonial 1 - tilted -->
                    <div class="md:rotate-[-1deg]">
                        <div class="relative">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-lime-300"></div>
                            <div class="relative border-[3px] border-black bg-white p-6 shadow-[4px_4px_0_0_#000]">
                                <div class="mb-3 flex gap-0.5">
                                    <Star v-for="n in 5" :key="n" class="h-4 w-4 fill-black" :stroke-width="0" />
                                </div>
                                <p class="text-base font-semibold leading-relaxed">
                                    "Replaced Jira, Confluence, and Slack in our sprint loop. We close standups 15 min faster and nobody complains about tools anymore."
                                </p>
                                <div class="mt-5 flex items-center gap-3 border-t-2 border-black pt-4">
                                    <div class="flex h-10 w-10 items-center justify-center border-2 border-black bg-pink-300 font-black">M</div>
                                    <div>
                                        <div class="text-sm font-black">Maya Chen</div>
                                        <div class="text-xs uppercase tracking-wider text-neutral-600">Eng Lead, Shipfast.io</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Testimonial 2 - centered, larger -->
                    <div class="md:translate-y-4">
                        <div class="relative">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-pink-300"></div>
                            <div class="relative border-[3px] border-black bg-black p-6 text-white shadow-[4px_4px_0_0_#000]">
                                <div class="mb-3 flex gap-0.5">
                                    <Star v-for="n in 5" :key="n" class="h-4 w-4 fill-lime-300" :stroke-width="0" />
                                </div>
                                <p class="text-base font-semibold leading-relaxed">
                                    "Self-hosted on a $20 VPS. Whole team uses it — devs, designers, our PM. <span class="bg-lime-300 px-1 text-black">Finally</span>, a workspace nobody hates."
                                </p>
                                <div class="mt-5 flex items-center gap-3 border-t-2 border-white pt-4">
                                    <div class="flex h-10 w-10 items-center justify-center border-2 border-white bg-lime-300 font-black text-black">D</div>
                                    <div>
                                        <div class="text-sm font-black">Diego Ramos</div>
                                        <div class="text-xs uppercase tracking-wider text-neutral-400">Founder, Forklore</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Testimonial 3 - tilted opposite -->
                    <div class="md:rotate-[1deg]">
                        <div class="relative">
                            <div class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black bg-cyan-300"></div>
                            <div class="relative border-[3px] border-black bg-white p-6 shadow-[4px_4px_0_0_#000]">
                                <div class="mb-3 flex gap-0.5">
                                    <Star v-for="n in 5" :key="n" class="h-4 w-4 fill-black" :stroke-width="0" />
                                </div>
                                <p class="text-base font-semibold leading-relaxed">
                                    "AI auto-triage is the real deal. I dump bug reports, it labels & assigns. Saved my designer brain from JQL forever."
                                </p>
                                <div class="mt-5 flex items-center gap-3 border-t-2 border-black pt-4">
                                    <div class="flex h-10 w-10 items-center justify-center border-2 border-black bg-yellow-300 font-black">A</div>
                                    <div>
                                        <div class="text-sm font-black">Anya Petrov</div>
                                        <div class="text-xs uppercase tracking-wider text-neutral-600">Design Lead, Tilt Studio</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>


        <section id="pricing" class="border-b-[3px] border-black bg-lime-100 py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="text-center">
                    <div class="inline-flex items-center gap-2 border-[3px] border-black bg-white px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]">
                        <Code2 class="h-3 w-3" :stroke-width="3" /> Pricing
                    </div>
                    <h2 class="mx-auto mt-6 max-w-3xl text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        Flat per-seat.<br />No "Premium Plus++".
                    </h2>

                    <!-- Billing toggle -->
                    <div class="mt-10 inline-flex border-[3px] border-black bg-white shadow-[4px_4px_0_0_#000]">
                        <button
                            @click="billingYearly = false"
                            :class="!billingYearly ? 'bg-black text-white' : 'bg-white text-black'"
                            class="px-5 py-2.5 text-xs font-bold uppercase tracking-wider transition-colors"
                        >
                            Monthly
                        </button>
                        <button
                            @click="billingYearly = true"
                            :class="billingYearly ? 'bg-black text-white' : 'bg-white text-black'"
                            class="border-l-[3px] border-black px-5 py-2.5 text-xs font-bold uppercase tracking-wider transition-colors"
                        >
                            Yearly · save 22%
                        </button>
                    </div>
                </div>

                <div class="mt-12 grid gap-6 md:grid-cols-3">
                    <div
                        v-for="t in tiers"
                        :key="t.name"
                        class="relative"
                        :class="t.highlighted && 'md:-translate-y-4'"
                    >
                        <div
                            class="absolute inset-0 translate-x-2 translate-y-2 border-[3px] border-black"
                            :class="t.highlighted ? 'bg-pink-400' : 'bg-black'"
                        ></div>
                        <div
                            class="relative flex h-full flex-col border-[3px] border-black p-8 shadow-[6px_6px_0_0_#000]"
                            :class="t.highlighted ? 'bg-lime-300' : 'bg-white'"
                        >
                            <div v-if="t.highlighted" class="absolute -top-4 left-1/2 -translate-x-1/2 border-[3px] border-black bg-black px-4 py-1 text-xs font-bold uppercase tracking-widest text-lime-300 shadow-[3px_3px_0_0_#000]">
                                ★ Most popular
                            </div>

                            <h3 class="text-2xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">{{ t.name }}</h3>
                            <p class="mt-1 text-sm text-neutral-700">{{ t.tagline }}</p>

                            <div class="mt-6 flex items-baseline gap-1">
                                <span class="text-5xl font-black tracking-tight" style="font-family: 'Archivo Black', sans-serif">
                                    ${{ billingYearly ? t.priceYearly : t.priceMonthly }}
                                </span>
                                <span class="text-sm font-bold text-neutral-700">/user/mo</span>
                            </div>
                            <p class="mt-1 text-xs uppercase tracking-wider text-neutral-600">
                                {{ billingYearly ? 'billed yearly' : 'billed monthly' }}
                            </p>

                            <ul class="mt-6 space-y-2.5 border-t-2 border-black pt-6">
                                <li v-for="f in t.features" :key="f" class="flex items-start gap-2 text-sm font-semibold">
                                    <div class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center border-2 border-black bg-black">
                                        <Check class="h-3 w-3 text-lime-300" :stroke-width="4" />
                                    </div>
                                    {{ f }}
                                </li>
                            </ul>

                            <Link
                                :href="route('register')"
                                class="mt-8 inline-flex w-full items-center justify-center gap-2 border-[3px] border-black px-6 py-3 text-sm font-bold uppercase tracking-wider shadow-[4px_4px_0_0_#000] transition-all hover:translate-x-[-2px] hover:translate-y-[-2px] hover:shadow-[6px_6px_0_0_#000] active:translate-x-0 active:translate-y-0 active:shadow-[2px_2px_0_0_#000]"
                                :class="t.highlighted ? 'bg-black text-lime-300' : 'bg-lime-300 text-black'"
                            >
                                {{ t.cta }}
                                <ArrowRight class="h-4 w-4" :stroke-width="3" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="faq" class="border-b-[3px] border-black py-20 md:py-28">
            <div class="mx-auto max-w-4xl px-4 md:px-8">
                <div class="text-center">
                    <div class="inline-flex items-center gap-2 border-[3px] border-black bg-cyan-300 px-4 py-1.5 text-xs font-bold uppercase tracking-widest shadow-[3px_3px_0_0_#000]">
                        Questions?
                    </div>
                    <h2 class="mt-6 text-4xl leading-[1.05] tracking-tight md:text-6xl" style="font-family: 'Archivo Black', sans-serif">
                        FAQ — the real ones.
                    </h2>
                </div>

                <div class="mt-12 space-y-4">
                    <div
                        v-for="(item, i) in faqs"
                        :key="i"
                        class="relative"
                    >
                        <div class="absolute inset-0 translate-x-1.5 translate-y-1.5 border-[3px] border-black bg-black"></div>
                        <div class="relative border-[3px] border-black bg-white">
                            <button
                                @click="toggleFaq(i)"
                                class="flex w-full items-center justify-between gap-4 p-5 text-left transition-colors hover:bg-lime-50 md:p-6"
                            >
                                <span class="text-base font-bold md:text-lg">{{ item.q }}</span>
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center border-[3px] border-black transition-all"
                                    :class="openFaq === i ? 'bg-black text-lime-300' : 'bg-lime-300 text-black'"
                                >
                                    <Plus v-if="openFaq !== i" class="h-4 w-4" :stroke-width="3" />
                                    <Minus v-else class="h-4 w-4" :stroke-width="3" />
                                </div>
                            </button>
                            <div v-if="openFaq === i" class="border-t-2 border-black bg-neutral-50 p-5 text-sm text-neutral-700 md:p-6 md:text-base">
                                {{ item.a }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="relative overflow-hidden border-b-[3px] border-black bg-lime-300 py-20 md:py-28">
            <!-- Decorative diagonal stripes -->
            <div class="absolute inset-0 opacity-10" style="background-image: repeating-linear-gradient(45deg, #000 0px, #000 2px, transparent 2px, transparent 14px);"></div>

            <div class="relative mx-auto max-w-5xl px-4 text-center md:px-8">
                <h2 class="text-5xl leading-[0.95] tracking-tight md:text-7xl lg:text-8xl" style="font-family: 'Archivo Black', sans-serif">
                    Ship the
                    <span class="inline-block bg-black px-3 -rotate-1 text-lime-300 shadow-[6px_6px_0_0_#000]">damn thing.</span>
                </h2>
                <p class="mx-auto mt-8 max-w-2xl text-lg text-neutral-800 md:text-xl">
                    Stop fighting your tools. Start shipping. 14-day free trial, no credit card, no sales call.
                </p>

                <div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                    <Link
                        :href="route('register')"
                        class="group inline-flex items-center justify-center gap-2 border-[3px] border-black bg-black px-10 py-5 text-base font-bold uppercase tracking-wider text-lime-300 shadow-[8px_8px_0_0_#000] transition-all hover:translate-x-[-3px] hover:translate-y-[-3px] hover:shadow-[11px_11px_0_0_#000] active:translate-x-0 active:translate-y-0 active:shadow-[3px_3px_0_0_#000]"
                    >
                        Start Free Today
                        <ArrowUpRight class="h-5 w-5 transition-transform group-hover:translate-x-1 group-hover:translate-y-[-2px]" :stroke-width="3" />
                    </Link>
                    <a
                        href="#"
                        class="inline-flex items-center justify-center gap-2 border-[3px] border-black bg-white px-8 py-5 text-base font-bold uppercase tracking-wider shadow-[8px_8px_0_0_#000] transition-all hover:translate-x-[-3px] hover:translate-y-[-3px] hover:shadow-[11px_11px_0_0_#000]"
                    >
                        <Github class="h-5 w-5" :stroke-width="3" />
                        Self-host instead
                    </a>
                </div>
            </div>
        </section>

        <footer class="bg-black py-16 text-white">
            <div class="mx-auto max-w-7xl px-4 md:px-8">
                <div class="grid gap-12 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center border-[3px] border-lime-300 bg-lime-300">
                                <Boxes class="h-5 w-5 text-black" :stroke-width="3" />
                            </div>
                            <span class="text-xl font-black uppercase tracking-tight" style="font-family: 'Archivo Black', sans-serif">Sprint Sync</span>
                        </div>
                        <p class="mt-5 max-w-md text-sm text-neutral-400">
                            The all-in-one workspace for cross-functional teams who want to ship, not configure. Self-hostable. AI-powered. Loved.
                        </p>
                    </div>
                    <div>
                        <h4 class="text-sm font-black uppercase tracking-widest text-lime-300">Product</h4>
                        <ul class="mt-4 space-y-2 text-sm text-neutral-400">
                            <li><a href="#features" class="hover:text-white">Features</a></li>
                            <li><a href="#compare" class="hover:text-white">Compare</a></li>
                            <li><a href="#pricing" class="hover:text-white">Pricing</a></li>
                            <li><a href="#" class="hover:text-white">Changelog</a></li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="text-sm font-black uppercase tracking-widest text-lime-300">Company</h4>
                        <ul class="mt-4 space-y-2 text-sm text-neutral-400">
                            <li><a href="#" class="hover:text-white">About</a></li>
                            <li><a href="#" class="hover:text-white">Blog</a></li>
                            <li><a href="#" class="hover:text-white">Careers</a></li>
                            <li><a href="#" class="hover:text-white">Contact</a></li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 flex flex-col items-start justify-between gap-4 border-t-2 border-neutral-800 pt-8 md:flex-row md:items-center">
                    <p class="text-xs text-neutral-500">© 2026 Sprint Sync. Built with rage against bad tools.</p>
                    <div class="flex gap-2">
                        <a href="#" class="border-2 border-neutral-700 p-2 transition-colors hover:border-lime-300 hover:text-lime-300">
                            <Github class="h-4 w-4" :stroke-width="2.5" />
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@keyframes marquee {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
}
.animate-marquee {
    animation: marquee 40s linear infinite;
}
</style>
