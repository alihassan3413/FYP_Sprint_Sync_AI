<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import {
  ArrowRight,
  ArrowUpRight,
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
  X,
  Zap,
} from "lucide-vue-next";
import { onMounted, ref } from "vue";

// FAQ accordion state
const openFaq = ref<number | null>(0);
const toggleFaq = (i: number) => (openFaq.value = openFaq.value === i ? null : i);

// Pricing toggle
const billingYearly = ref(true);

const stats = ref([
  { label: "Avg. Issue Triage Time", value: "0.4s", sub: "AI-classified instantly" },
  { label: "Setup Time", value: "< 5min", sub: "vs Jira’s 2 weeks" },
  {
    label: "Tools Replaced",
    value: "6+",
    sub: "kanban, docs, chat, sprints, wiki, time",
  },
  { label: "Self-Hosting", value: "100%", sub: "your data, your servers" },
]);

const mounted = ref(false);
onMounted(() => {
  mounted.value = true;
});

const faqs = [
  {
    q: "How is Sprint Sync different from Jira?",
    a:
      "Jira buries you in configuration. Sprint Sync ships with sensible defaults — boards, sprints, and AI triage work in 5 minutes flat. No Scrum Master required, no certification, no part-time admin. And it’s self-hostable, so your roadmap never lives on someone else’s server.",
  },
  {
    q: "Why not just use Linear?",
    a:
      "Linear is fast — we love it — but it’s built for engineers only. Your PMs, designers, and ops folks bounce off the mental model. Sprint Sync gives engineering Linear-grade speed AND gives the rest of the org a workspace they’ll actually open. One tool, no tribal silos.",
  },
  {
    q: "Is the AI actually useful or just a marketing checkbox?",
    a:
      "Real work, no theatre. Sprint Sync AI auto-triages incoming tickets, drafts release notes from your sprint, summarizes long comment threads into 3 bullets, and turns voice notes into structured issues. It runs on your infra if you self-host — your data stays yours.",
  },
  {
    q: 'What does "all-in-one" actually mean here?',
    a:
      "Kanban boards + sprint planning + docs/wiki + threaded comments + time tracking + roadmaps — all native, not bolted on. You stop paying for Notion + Slack + Jira + Toggl + Confluence. One workspace, one bill, one source of truth.",
  },
  {
    q: "Can I self-host it?",
    a:
      "Yes. Sprint Sync ships with a Docker compose file and detailed self-host docs. Run it on your own VPS, in your VPC, or air-gapped on a laptop. Source code is open. Your roadmap, your servers, your rules.",
  },
  {
    q: "How is the pricing structured?",
    a:
      'Flat per-seat pricing, no enterprise upsell traps. Free tier covers up to 10 users — same as Jira but without the configuration nightmare. Paid plans unlock advanced AI agents, SSO, and unlimited automations. No "Premium" / "Premium+" / "Premium Plus" ladder.',
  },
];

const compareRows = [
  {
    label: "Setup time",
    sprintsync: "< 5 minutes",
    jira: "1-2 weeks",
    linear: "~10 minutes",
    clickup: "2-3 weeks",
  },
  {
    label: "Built for non-devs",
    sprintsync: true,
    jira: "sort of",
    linear: false,
    clickup: true,
  },
  {
    label: "Native AI triage",
    sprintsync: true,
    jira: "add-on",
    linear: "partial",
    clickup: "add-on",
  },
  {
    label: "Self-hostable",
    sprintsync: true,
    jira: false,
    linear: false,
    clickup: false,
  },
  {
    label: "All-in-one (docs + chat + boards)",
    sprintsync: true,
    jira: false,
    linear: false,
    clickup: "bloated",
  },
  {
    label: "Keyboard-first UX",
    sprintsync: true,
    jira: false,
    linear: true,
    clickup: false,
  },
  {
    label: "Source-available",
    sprintsync: true,
    jira: false,
    linear: false,
    clickup: false,
  },
  {
    label: "Configuration debt",
    sprintsync: "none",
    jira: "crushing",
    linear: "low",
    clickup: "high",
  },
];

const tiers = [
  {
    name: "Solo",
    tagline: "For indie hackers & freelancers",
    priceMonthly: 0,
    priceYearly: 0,
    cta: "Start free",
    highlighted: false,
    features: [
      "Up to 10 users",
      "Unlimited projects",
      "Kanban + sprints",
      "Basic AI assist (50/mo)",
      "Community support",
    ],
  },
  {
    name: "Team",
    tagline: "For growing teams that ship",
    priceMonthly: 9,
    priceYearly: 7,
    cta: "Start 14-day trial",
    highlighted: true,
    features: [
      "Unlimited users",
      "AI auto-triage & summaries",
      "Docs + Wiki",
      "Roadmaps + Goals",
      "SSO + advanced permissions",
      "Priority support",
    ],
  },
  {
    name: "Self-Hosted",
    tagline: "For privacy-first orgs",
    priceMonthly: 19,
    priceYearly: 15,
    cta: "Get the binary",
    highlighted: false,
    features: [
      "Everything in Team",
      "Run on your infra",
      "Air-gapped deployment OK",
      "Source code access",
      "Custom AI model endpoints",
      "Dedicated Slack channel",
    ],
  },
];
</script>

<template>
  <Head title="Sprint Sync — The All-in-One Workspace That Doesn't Suck">
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700;9..144,800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap"
      rel="stylesheet"
    />
  </Head>

  <div
    class="min-h-screen bg-[#FAF8F3] text-[#1A1A1A] antialiased selection:bg-[#7FB069]/30"
    style="font-family: 'Inter', sans-serif"
  >
    <!-- Quiet announcement bar -->
    <div class="border-b border-[#1A1A1A]/10 bg-[#FAF8F3]">
      <div
        class="mx-auto flex max-w-7xl items-center justify-center gap-2 px-4 py-2 text-xs text-[#1A1A1A]/70"
      >
        <span class="h-1.5 w-1.5 rounded-full bg-[#7FB069]"></span>
        v1.0 now in public beta
        <span class="text-[#1A1A1A]/30">·</span>
        <a href="#" class="font-medium underline-offset-2 hover:underline"
          >Read the launch post</a
        >
      </div>
    </div>

    <header
      class="sticky top-0 z-40 border-b border-[#1A1A1A]/10 bg-[#FAF8F3]/85 backdrop-blur-md"
    >
      <nav class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 md:px-8">
        <Link :href="route('home')" class="group flex items-center gap-2.5">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-lg border border-[#1A1A1A]/15 bg-[#7FB069]/15 transition-all group-hover:bg-[#7FB069]/25"
          >
            <Boxes class="h-4.5 w-4.5 text-[#7FB069]" :stroke-width="2.25" />
          </div>
          <span
            class="text-[17px] font-semibold tracking-tight"
            style="font-family: 'Fraunces', serif"
          >
            Sprint Sync
          </span>
        </Link>

        <div class="hidden items-center gap-8 lg:flex">
          <a
            href="#features"
            class="text-sm text-[#1A1A1A]/70 transition-colors hover:text-[#1A1A1A]"
            >Features</a
          >
          <a
            href="#compare"
            class="text-sm text-[#1A1A1A]/70 transition-colors hover:text-[#1A1A1A]"
            >Compare</a
          >
          <a
            href="#pricing"
            class="text-sm text-[#1A1A1A]/70 transition-colors hover:text-[#1A1A1A]"
            >Pricing</a
          >
          <a
            href="#faq"
            class="text-sm text-[#1A1A1A]/70 transition-colors hover:text-[#1A1A1A]"
            >FAQ</a
          >
        </div>

        <div class="flex items-center gap-3">
          <Link
            v-if="$page.props.auth?.user"
            :href="
              route('dashboard', { workspace: $page.props.workspace?.current?.slug })
            "
            class="rounded-full border border-[#1A1A1A]/15 bg-white px-4 py-2 text-sm font-medium transition-all hover:border-[#1A1A1A]/30 hover:shadow-sm"
          >
            Dashboard
          </Link>
          <template v-else>
            <Link
              :href="route('login')"
              class="hidden text-sm text-[#1A1A1A]/70 transition-colors hover:text-[#1A1A1A] sm:inline"
              >Log in</Link
            >
            <Link
              :href="route('register')"
              class="rounded-full bg-[#1A1A1A] px-4 py-2 text-sm font-medium text-[#FAF8F3] transition-all hover:bg-[#1A1A1A]/90"
            >
              Get Started
            </Link>
          </template>
        </div>
      </nav>
    </header>

    <!-- HERO -->
    <section class="relative overflow-hidden">
      <!-- Subtle decorative blur shape -->
      <div
        class="absolute top-20 right-10 h-72 w-72 rounded-full bg-[#7FB069]/15 blur-3xl"
      ></div>
      <div
        class="absolute top-40 left-0 h-64 w-64 rounded-full bg-[#F4C7A1]/20 blur-3xl"
      ></div>

      <div
        class="relative mx-auto max-w-7xl px-4 pt-16 pb-20 md:px-8 md:pt-24 md:pb-28 lg:pt-32"
      >
        <div class="mx-auto max-w-4xl text-center">
          <!-- Tag pill -->
          <div
            class="inline-flex items-center gap-2 rounded-full border border-[#1A1A1A]/10 bg-white/60 px-3.5 py-1.5 text-xs text-[#1A1A1A]/70 backdrop-blur-sm"
          >
            <Sparkles class="h-3 w-3 text-[#7FB069]" :stroke-width="2.5" />
            Built for teams who ship — not for those who configure
          </div>

          <h1
            class="mt-8 text-5xl leading-[1.05] tracking-[-0.02em] md:text-7xl lg:text-[5.5rem]"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            The workspace
            <span class="block">
              that <em class="font-light text-[#7FB069] italic">actually</em> ships.
            </span>
          </h1>

          <p
            class="mx-auto mt-7 max-w-2xl text-lg leading-relaxed text-[#1A1A1A]/65 md:text-xl"
          >
            Jira is a swamp. Linear is dev-only. ClickUp is a graveyard. Sprint Sync is
            the calm, all-in-one workspace your engineers, designers, and PMs all open
            every morning.
          </p>

          <div
            class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row"
          >
            <Link
              :href="route('register')"
              class="group inline-flex items-center justify-center gap-2 rounded-full bg-[#1A1A1A] px-7 py-3.5 text-sm font-medium text-[#FAF8F3] transition-all hover:bg-[#1A1A1A]/90 hover:shadow-lg"
            >
              Start free — no card
              <ArrowRight
                class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                :stroke-width="2.25"
              />
            </Link>
            <a
              href="#compare"
              class="inline-flex items-center justify-center gap-2 rounded-full border border-[#1A1A1A]/15 bg-white/60 px-7 py-3.5 text-sm font-medium backdrop-blur-sm transition-all hover:border-[#1A1A1A]/30"
            >
              See the comparison
              <ChevronRight class="h-4 w-4" :stroke-width="2.25" />
            </a>
          </div>

          <div
            class="mt-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-[#1A1A1A]/50"
          >
            <span class="flex items-center gap-1.5"
              ><Check class="h-3.5 w-3.5 text-[#7FB069]" :stroke-width="2.5" /> 14-day
              free trial</span
            >
            <span class="flex items-center gap-1.5"
              ><Check class="h-3.5 w-3.5 text-[#7FB069]" :stroke-width="2.5" />
              Self-hostable</span
            >
            <span class="flex items-center gap-1.5"
              ><Check class="h-3.5 w-3.5 text-[#7FB069]" :stroke-width="2.5" /> Open
              source core</span
            >
          </div>
        </div>

        <!-- Product preview card -->
        <div class="relative mx-auto mt-20 max-w-5xl">
          <div
            class="rounded-2xl border border-[#1A1A1A]/10 bg-white p-2 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.15)]"
          >
            <div class="rounded-xl bg-[#FAF8F3] p-5">
              <!-- Window chrome -->
              <div class="mb-5 flex items-center gap-2">
                <div class="h-2.5 w-2.5 rounded-full bg-[#1A1A1A]/15"></div>
                <div class="h-2.5 w-2.5 rounded-full bg-[#1A1A1A]/15"></div>
                <div class="h-2.5 w-2.5 rounded-full bg-[#1A1A1A]/15"></div>
                <span class="ml-3 font-mono text-xs text-[#1A1A1A]/40"
                  >sprintsync.app/board</span
                >
              </div>

              <!-- Mock kanban -->
              <div class="grid grid-cols-3 gap-3">
                <div class="space-y-2">
                  <div
                    class="flex items-center justify-between text-xs font-medium text-[#1A1A1A]/60"
                  >
                    <span>To Do</span><span class="text-[#1A1A1A]/30">3</span>
                  </div>
                  <div
                    class="rounded-lg border border-[#1A1A1A]/10 bg-white p-3 text-xs shadow-sm"
                  >
                    <div class="font-medium">Auth flow</div>
                    <div class="mt-1 text-[10px] text-[#1A1A1A]/40">SS-12</div>
                  </div>
                  <div
                    class="rounded-lg border border-[#1A1A1A]/10 bg-white p-3 text-xs shadow-sm"
                  >
                    <div class="font-medium">OAuth setup</div>
                    <div class="mt-1 text-[10px] text-[#1A1A1A]/40">SS-13</div>
                  </div>
                </div>
                <div class="space-y-2">
                  <div
                    class="flex items-center justify-between text-xs font-medium text-[#1A1A1A]/60"
                  >
                    <span>In Progress</span><span class="text-[#1A1A1A]/30">2</span>
                  </div>
                  <div
                    class="rounded-lg border border-[#7FB069]/30 bg-white p-3 text-xs shadow-sm"
                  >
                    <div class="font-medium">Kanban DnD</div>
                    <div class="mt-1 text-[10px] text-[#1A1A1A]/40">SS-14</div>
                    <div
                      class="mt-2 inline-flex items-center gap-1 rounded-full bg-[#7FB069]/15 px-2 py-0.5 text-[9px] font-medium text-[#5d8a4d]"
                    >
                      <Sparkles class="h-2 w-2" :stroke-width="2.5" /> AI assisted
                    </div>
                  </div>
                </div>
                <div class="space-y-2">
                  <div
                    class="flex items-center justify-between text-xs font-medium text-[#1A1A1A]/60"
                  >
                    <span>Done</span><span class="text-[#1A1A1A]/30">8</span>
                  </div>
                  <div
                    class="rounded-lg border border-[#1A1A1A]/10 bg-white p-3 text-xs shadow-sm opacity-60"
                  >
                    <div class="font-medium line-through">Schema</div>
                    <div class="mt-1 text-[10px] text-[#1A1A1A]/40">SS-11</div>
                  </div>
                </div>
              </div>

              <!-- AI assist popup -->
              <div
                class="mt-5 flex items-start gap-3 rounded-xl border border-[#7FB069]/25 bg-[#7FB069]/8 p-3"
              >
                <div
                  class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-[#7FB069]/20"
                >
                  <Sparkles class="h-4 w-4 text-[#5d8a4d]" :stroke-width="2.25" />
                </div>
                <div class="text-xs">
                  <div class="font-medium text-[#1A1A1A]">
                    AI auto-triaged 4 tickets
                  </div>
                  <div class="mt-0.5 text-[#1A1A1A]/55">
                    Assigned to @ali, @sara · saved 12 min
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Stats row -->
        <div class="mt-20 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="s in stats"
            :key="s.label"
            class="border-l-2 border-[#7FB069]/30 pl-5"
          >
            <div
              class="text-4xl tracking-tight"
              style="font-family: 'Fraunces', serif; font-weight: 500"
            >
              {{ s.value }}
            </div>
            <div class="mt-1.5 text-sm font-medium text-[#1A1A1A]/75">{{ s.label }}</div>
            <div class="mt-1 text-xs text-[#1A1A1A]/45">{{ s.sub }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- THE PROBLEM -->
    <section class="border-t border-[#1A1A1A]/8 bg-[#EDE6D9]/40 py-20 md:py-28">
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            The problem
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            Every project tool has a
            <em class="font-light italic text-[#1A1A1A]/60">brand problem.</em>
          </h2>
          <p class="mt-5 text-lg text-[#1A1A1A]/60">
            Every sprint planning meeting starts with someone complaining about the tool.
            We got tired of it. So we built the one we wished existed.
          </p>
        </div>

        <div class="mx-auto mt-16 grid max-w-5xl gap-5 md:grid-cols-3">
          <div
            class="rounded-2xl border border-[#1A1A1A]/8 bg-white p-7 transition-all hover:border-[#1A1A1A]/15"
          >
            <div
              class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1A1A1A]/5"
            >
              <X class="h-5 w-5 text-[#1A1A1A]/60" :stroke-width="2.25" />
            </div>
            <h3
              class="mt-5 text-xl tracking-tight"
              style="font-family: 'Fraunces', serif; font-weight: 600"
            >
              Jira
            </h3>
            <p class="mt-1 text-xs font-medium tracking-wide text-[#1A1A1A]/50">
              The configuration swamp
            </p>
            <p class="mt-4 text-sm leading-relaxed text-[#1A1A1A]/65">
              Two weeks of setup. A part-time admin. Custom fields no one remembers
              adding. By month six, your workflow looks like a Visio diagram from 2008.
            </p>
          </div>

          <div
            class="rounded-2xl border border-[#1A1A1A]/8 bg-white p-7 transition-all hover:border-[#1A1A1A]/15"
          >
            <div
              class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1A1A1A]/5"
            >
              <Minus class="h-5 w-5 text-[#1A1A1A]/60" :stroke-width="2.25" />
            </div>
            <h3
              class="mt-5 text-xl tracking-tight"
              style="font-family: 'Fraunces', serif; font-weight: 600"
            >
              Linear
            </h3>
            <p class="mt-1 text-xs font-medium tracking-wide text-[#1A1A1A]/50">
              The dev-only club
            </p>
            <p class="mt-4 text-sm leading-relaxed text-[#1A1A1A]/65">
              Fast — beautiful, even. But PMs bounce off the keyboard shortcuts, designers
              can't find the docs, and ops gets locked out. Tribal silos.
            </p>
          </div>

          <div
            class="rounded-2xl border border-[#1A1A1A]/8 bg-white p-7 transition-all hover:border-[#1A1A1A]/15"
          >
            <div
              class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#1A1A1A]/5"
            >
              <Layers class="h-5 w-5 text-[#1A1A1A]/60" :stroke-width="2.25" />
            </div>
            <h3
              class="mt-5 text-xl tracking-tight"
              style="font-family: 'Fraunces', serif; font-weight: 600"
            >
              ClickUp
            </h3>
            <p class="mt-1 text-xs font-medium tracking-wide text-[#1A1A1A]/50">
              Feature graveyard
            </p>
            <p class="mt-4 text-sm leading-relaxed text-[#1A1A1A]/65">
              17 view types, 40 custom field options, 12 automation triggers. Half are
              half-baked. New hires need three weeks to find anything.
            </p>
          </div>
        </div>

        <!-- And then there's us -->
        <div
          class="mx-auto mt-12 max-w-5xl rounded-3xl border border-[#7FB069]/25 bg-gradient-to-br from-[#7FB069]/10 to-[#F4C7A1]/15 p-8 md:p-12"
        >
          <div class="flex flex-col items-start gap-6 md:flex-row md:items-center">
            <div
              class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white shadow-sm"
            >
              <Boxes class="h-7 w-7 text-[#7FB069]" :stroke-width="2" />
            </div>
            <div>
              <p
                class="text-xs font-medium tracking-widest text-[#5d8a4d] uppercase"
              >
                And then there's us
              </p>
              <h3
                class="mt-2 text-2xl leading-[1.15] tracking-tight md:text-4xl"
                style="font-family: 'Fraunces', serif; font-weight: 500"
              >
                Sprint Sync is the workspace your whole team will actually open.
              </h3>
              <p class="mt-4 max-w-2xl text-[#1A1A1A]/65">
                Linear-grade speed for engineers. Notion-style docs for PMs.
                Figma-friendly comments for designers. AI that does the boring work.
                Self-host if you want.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- FEATURES -->
    <section id="features" class="py-20 md:py-28">
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            What you get
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            Everything your team needs.
            <em class="font-light italic text-[#1A1A1A]/55"
              >Nothing it doesn't.</em
            >
          </h2>
        </div>

        <div class="mt-16 grid gap-5 lg:grid-cols-12">
          <!-- Big AI feature -->
          <div class="lg:col-span-7">
            <div
              class="h-full rounded-3xl border border-[#1A1A1A]/8 bg-white p-8 md:p-10"
            >
              <div
                class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#7FB069]/15"
              >
                <Sparkles class="h-6 w-6 text-[#5d8a4d]" :stroke-width="2" />
              </div>
              <h3
                class="mt-6 text-2xl tracking-tight md:text-3xl"
                style="font-family: 'Fraunces', serif; font-weight: 500"
              >
                AI that does the boring work.
              </h3>
              <p class="mt-3 text-[#1A1A1A]/65">
                Drop a bug report into the queue — Sprint Sync AI auto-classifies it,
                tags it, assigns it to the right engineer, and drafts the first reply.
              </p>

              <div class="mt-7 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-[#FAF8F3] p-4">
                  <div
                    class="text-xs font-medium tracking-wide text-[#1A1A1A]/45 uppercase"
                  >
                    Auto-triage
                  </div>
                  <div class="mt-1 text-sm font-medium">99% label accuracy</div>
                </div>
                <div class="rounded-xl bg-[#FAF8F3] p-4">
                  <div
                    class="text-xs font-medium tracking-wide text-[#1A1A1A]/45 uppercase"
                  >
                    Sprint summary
                  </div>
                  <div class="mt-1 text-sm font-medium">3-bullet TL;DR Fridays</div>
                </div>
                <div class="rounded-xl bg-[#FAF8F3] p-4">
                  <div
                    class="text-xs font-medium tracking-wide text-[#1A1A1A]/45 uppercase"
                  >
                    Voice → Issue
                  </div>
                  <div class="mt-1 text-sm font-medium">Speak. We structure.</div>
                </div>
                <div class="rounded-xl bg-[#FAF8F3] p-4">
                  <div
                    class="text-xs font-medium tracking-wide text-[#1A1A1A]/45 uppercase"
                  >
                    Release notes
                  </div>
                  <div class="mt-1 text-sm font-medium">Drafted from your sprint</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Six tools card -->
          <div class="lg:col-span-5">
            <div
              class="h-full rounded-3xl bg-[#1A1A1A] p-8 text-[#FAF8F3] md:p-10"
            >
              <div
                class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#7FB069]/20"
              >
                <Layers class="h-6 w-6 text-[#7FB069]" :stroke-width="2" />
              </div>
              <h3
                class="mt-6 text-2xl tracking-tight md:text-3xl"
                style="font-family: 'Fraunces', serif; font-weight: 500"
              >
                One tool. Six tools' worth of work.
              </h3>
              <p class="mt-3 text-[#FAF8F3]/65">
                Stop paying for Notion + Slack + Jira + Toggl + Confluence. We bundled
                it.
              </p>
              <ul class="mt-6 space-y-3">
                <li class="flex items-center gap-3 text-sm">
                  <Kanban class="h-4 w-4 text-[#7FB069]" :stroke-width="2.25" />
                  <span class="text-[#FAF8F3]/85">Kanban + Sprints</span>
                </li>
                <li class="flex items-center gap-3 text-sm">
                  <FileText class="h-4 w-4 text-[#7FB069]" :stroke-width="2.25" />
                  <span class="text-[#FAF8F3]/85">Docs &amp; Wiki</span>
                </li>
                <li class="flex items-center gap-3 text-sm">
                  <MessageSquare
                    class="h-4 w-4 text-[#7FB069]"
                    :stroke-width="2.25"
                  />
                  <span class="text-[#FAF8F3]/85">Threaded comments</span>
                </li>
                <li class="flex items-center gap-3 text-sm">
                  <LineChart class="h-4 w-4 text-[#7FB069]" :stroke-width="2.25" />
                  <span class="text-[#FAF8F3]/85">Roadmaps &amp; goals</span>
                </li>
                <li class="flex items-center gap-3 text-sm">
                  <LayoutDashboard
                    class="h-4 w-4 text-[#7FB069]"
                    :stroke-width="2.25"
                  />
                  <span class="text-[#FAF8F3]/85">Custom dashboards</span>
                </li>
              </ul>
            </div>
          </div>

          <!-- Self host -->
          <div class="lg:col-span-4">
            <div
              class="h-full rounded-3xl border border-[#1A1A1A]/8 bg-white p-7"
            >
              <div
                class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#F4C7A1]/25"
              >
                <Server class="h-5 w-5 text-[#b8794a]" :stroke-width="2.25" />
              </div>
              <h3
                class="mt-5 text-xl tracking-tight"
                style="font-family: 'Fraunces', serif; font-weight: 600"
              >
                Self-host. Sleep well.
              </h3>
              <p class="mt-2 text-sm text-[#1A1A1A]/65">
                Docker compose. Run on your VPS or air-gapped. Your roadmap never lives
                on someone else's server.
              </p>
              <code
                class="mt-5 block rounded-xl bg-[#1A1A1A] p-3 font-mono text-xs text-[#7FB069]"
              >
                $ docker compose up -d<br />
                <span class="text-[#FAF8F3]/40"># sprint-sync on :8080</span>
              </code>
            </div>
          </div>

          <!-- Keyboard -->
          <div class="lg:col-span-4">
            <div
              class="h-full rounded-3xl border border-[#1A1A1A]/8 bg-white p-7"
            >
              <div
                class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#7FB069]/15"
              >
                <Zap class="h-5 w-5 text-[#5d8a4d]" :stroke-width="2.25" />
              </div>
              <h3
                class="mt-5 text-xl tracking-tight"
                style="font-family: 'Fraunces', serif; font-weight: 600"
              >
                Keyboard-first.
              </h3>
              <p class="mt-2 text-sm text-[#1A1A1A]/65">
                Every action in 1-2 keystrokes. Command palette opens with
                <kbd class="rounded-md bg-[#FAF8F3] px-1.5 py-0.5 font-mono text-[10px]"
                  >⌘K</kbd
                >. Mouse optional.
              </p>
              <div class="mt-5 flex flex-wrap gap-1.5">
                <kbd
                  class="rounded-md border border-[#1A1A1A]/10 bg-[#FAF8F3] px-2 py-1 font-mono text-[10px] font-medium"
                  >⌘K</kbd
                >
                <kbd
                  class="rounded-md border border-[#1A1A1A]/10 bg-[#FAF8F3] px-2 py-1 font-mono text-[10px] font-medium"
                  >C</kbd
                >
                <kbd
                  class="rounded-md border border-[#1A1A1A]/10 bg-[#FAF8F3] px-2 py-1 font-mono text-[10px] font-medium"
                  >⌘↵</kbd
                >
                <kbd
                  class="rounded-md border border-[#1A1A1A]/10 bg-[#FAF8F3] px-2 py-1 font-mono text-[10px] font-medium"
                  >G→I</kbd
                >
              </div>
            </div>
          </div>

          <!-- Open source -->
          <div class="lg:col-span-4">
            <div
              class="h-full rounded-3xl border border-[#1A1A1A]/8 bg-white p-7"
            >
              <div
                class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#F4C7A1]/25"
              >
                <Github class="h-5 w-5 text-[#b8794a]" :stroke-width="2.25" />
              </div>
              <h3
                class="mt-5 text-xl tracking-tight"
                style="font-family: 'Fraunces', serif; font-weight: 600"
              >
                Source-available.
              </h3>
              <p class="mt-2 text-sm text-[#1A1A1A]/65">
                Read the code. Audit the AI. Fork it. Submit PRs. Lock-in is for vendors
                who don't trust themselves.
              </p>
              <a
                href="https://github.com/alihassan3413/FYP_Sprint_Sync_AI"
                class="mt-5 inline-flex items-center gap-1.5 self-start rounded-full border border-[#1A1A1A]/10 bg-[#FAF8F3] px-3 py-1.5 text-xs font-medium transition-all hover:border-[#1A1A1A]/25"
              >
                <Star class="h-3 w-3" :stroke-width="2.25" />
                Star on GitHub
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- COMPARE -->
    <section
      id="compare"
      class="border-t border-[#1A1A1A]/8 bg-[#EDE6D9]/40 py-20 md:py-28"
    >
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            Side by side
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            How we compare.
            <em class="font-light italic text-[#1A1A1A]/55">Honestly.</em>
          </h2>
        </div>

        <div
          class="mx-auto mt-12 max-w-6xl overflow-x-auto rounded-2xl border border-[#1A1A1A]/8 bg-white"
        >
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="border-b border-[#1A1A1A]/8">
                <th class="p-5 text-xs font-medium tracking-wider text-[#1A1A1A]/50 uppercase">
                  Feature
                </th>
                <th
                  class="bg-[#7FB069]/10 p-5 text-xs font-semibold tracking-wider text-[#5d8a4d] uppercase"
                >
                  Sprint Sync
                </th>
                <th class="p-5 text-xs font-medium tracking-wider text-[#1A1A1A]/50 uppercase">
                  Jira
                </th>
                <th class="p-5 text-xs font-medium tracking-wider text-[#1A1A1A]/50 uppercase">
                  Linear
                </th>
                <th class="p-5 text-xs font-medium tracking-wider text-[#1A1A1A]/50 uppercase">
                  ClickUp
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in compareRows"
                :key="row.label"
                class="border-b border-[#1A1A1A]/5 last:border-b-0"
              >
                <td class="p-5 font-medium text-[#1A1A1A]/85">{{ row.label }}</td>
                <td class="bg-[#7FB069]/5 p-5 font-medium">
                  <span
                    v-if="row.sprintsync === true"
                    class="inline-flex items-center gap-1.5 text-[#5d8a4d]"
                  >
                    <Check class="h-4 w-4" :stroke-width="2.5" /> Yes
                  </span>
                  <span
                    v-else-if="row.sprintsync === false"
                    class="inline-flex items-center gap-1.5 text-[#1A1A1A]/30"
                  >
                    <X class="h-4 w-4" :stroke-width="2.5" /> No
                  </span>
                  <span v-else>{{ row.sprintsync }}</span>
                </td>
                <td class="p-5 text-[#1A1A1A]/70">
                  <span
                    v-if="row.jira === true"
                    class="inline-flex items-center gap-1.5"
                  >
                    <Check class="h-4 w-4" :stroke-width="2.25" /> Yes
                  </span>
                  <span
                    v-else-if="row.jira === false"
                    class="inline-flex items-center gap-1.5 text-[#1A1A1A]/30"
                  >
                    <X class="h-4 w-4" :stroke-width="2.25" /> No
                  </span>
                  <span v-else>{{ row.jira }}</span>
                </td>
                <td class="p-5 text-[#1A1A1A]/70">
                  <span
                    v-if="row.linear === true"
                    class="inline-flex items-center gap-1.5"
                  >
                    <Check class="h-4 w-4" :stroke-width="2.25" /> Yes
                  </span>
                  <span
                    v-else-if="row.linear === false"
                    class="inline-flex items-center gap-1.5 text-[#1A1A1A]/30"
                  >
                    <X class="h-4 w-4" :stroke-width="2.25" /> No
                  </span>
                  <span v-else>{{ row.linear }}</span>
                </td>
                <td class="p-5 text-[#1A1A1A]/70">
                  <span
                    v-if="row.clickup === true"
                    class="inline-flex items-center gap-1.5"
                  >
                    <Check class="h-4 w-4" :stroke-width="2.25" /> Yes
                  </span>
                  <span
                    v-else-if="row.clickup === false"
                    class="inline-flex items-center gap-1.5 text-[#1A1A1A]/30"
                  >
                    <X class="h-4 w-4" :stroke-width="2.25" /> No
                  </span>
                  <span v-else>{{ row.clickup }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- TESTIMONIALS -->
    <section class="py-20 md:py-28">
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            Loved by builders
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            People are saying things.
          </h2>
        </div>

        <div class="mx-auto mt-14 grid max-w-6xl gap-5 md:grid-cols-3">
          <div class="rounded-2xl border border-[#1A1A1A]/8 bg-white p-7">
            <div class="flex gap-0.5">
              <Star
                v-for="n in 5"
                :key="n"
                class="h-3.5 w-3.5 fill-[#7FB069] text-[#7FB069]"
                :stroke-width="0"
              />
            </div>
            <p
              class="mt-5 text-[15px] leading-relaxed text-[#1A1A1A]/80"
              style="font-family: 'Fraunces', serif; font-weight: 400"
            >
              "Replaced Jira, Confluence, and Slack in our sprint loop. We close standups
              15 min faster and nobody complains about tools anymore."
            </p>
            <div class="mt-6 flex items-center gap-3 border-t border-[#1A1A1A]/8 pt-5">
              <div
                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#7FB069]/20 text-sm font-medium text-[#5d8a4d]"
              >
                M
              </div>
              <div>
                <div class="text-sm font-medium">Maya Chen</div>
                <div class="text-xs text-[#1A1A1A]/50">
                  Eng Lead, Shipfast.io
                </div>
              </div>
            </div>
          </div>

          <div
            class="rounded-2xl bg-[#1A1A1A] p-7 text-[#FAF8F3] md:-translate-y-2"
          >
            <div class="flex gap-0.5">
              <Star
                v-for="n in 5"
                :key="n"
                class="h-3.5 w-3.5 fill-[#7FB069] text-[#7FB069]"
                :stroke-width="0"
              />
            </div>
            <p
              class="mt-5 text-[15px] leading-relaxed text-[#FAF8F3]/90"
              style="font-family: 'Fraunces', serif; font-weight: 400"
            >
              "Self-hosted on a $20 VPS. Whole team uses it — devs, designers, our PM.
              Finally, a workspace nobody hates."
            </p>
            <div class="mt-6 flex items-center gap-3 border-t border-[#FAF8F3]/15 pt-5">
              <div
                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#7FB069]/25 text-sm font-medium text-[#7FB069]"
              >
                D
              </div>
              <div>
                <div class="text-sm font-medium">Diego Ramos</div>
                <div class="text-xs text-[#FAF8F3]/50">Founder, Forklore</div>
              </div>
            </div>
          </div>

          <div class="rounded-2xl border border-[#1A1A1A]/8 bg-white p-7">
            <div class="flex gap-0.5">
              <Star
                v-for="n in 5"
                :key="n"
                class="h-3.5 w-3.5 fill-[#7FB069] text-[#7FB069]"
                :stroke-width="0"
              />
            </div>
            <p
              class="mt-5 text-[15px] leading-relaxed text-[#1A1A1A]/80"
              style="font-family: 'Fraunces', serif; font-weight: 400"
            >
              "AI auto-triage is the real deal. I dump bug reports, it labels &
              assigns. Saved my designer brain from JQL forever."
            </p>
            <div class="mt-6 flex items-center gap-3 border-t border-[#1A1A1A]/8 pt-5">
              <div
                class="flex h-9 w-9 items-center justify-center rounded-full bg-[#F4C7A1]/30 text-sm font-medium text-[#b8794a]"
              >
                A
              </div>
              <div>
                <div class="text-sm font-medium">Anya Petrov</div>
                <div class="text-xs text-[#1A1A1A]/50">
                  Design Lead, Tilt Studio
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- PRICING -->
    <section
      id="pricing"
      class="border-t border-[#1A1A1A]/8 bg-[#EDE6D9]/40 py-20 md:py-28"
    >
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="mx-auto max-w-2xl text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            Pricing
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            Flat per-seat.
            <em class="font-light italic text-[#1A1A1A]/55"
              >No "Premium Plus++".</em
            >
          </h2>

          <div
            class="mt-8 inline-flex rounded-full border border-[#1A1A1A]/10 bg-white p-1"
          >
            <button
              @click="billingYearly = false"
              :class="
                !billingYearly
                  ? 'bg-[#1A1A1A] text-[#FAF8F3]'
                  : 'text-[#1A1A1A]/60'
              "
              class="rounded-full px-5 py-2 text-xs font-medium transition-all"
            >
              Monthly
            </button>
            <button
              @click="billingYearly = true"
              :class="
                billingYearly
                  ? 'bg-[#1A1A1A] text-[#FAF8F3]'
                  : 'text-[#1A1A1A]/60'
              "
              class="rounded-full px-5 py-2 text-xs font-medium transition-all"
            >
              Yearly · save 22%
            </button>
          </div>
        </div>

        <div class="mx-auto mt-12 grid max-w-5xl gap-5 md:grid-cols-3">
          <div
            v-for="t in tiers"
            :key="t.name"
            class="relative flex flex-col rounded-3xl p-8"
            :class="
              t.highlighted
                ? 'border-2 border-[#7FB069] bg-white shadow-[0_20px_60px_-20px_rgba(127,176,105,0.4)]'
                : 'border border-[#1A1A1A]/8 bg-white'
            "
          >
            <div
              v-if="t.highlighted"
              class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-[#7FB069] px-3 py-1 text-[10px] font-semibold tracking-widest text-white uppercase"
            >
              Most popular
            </div>

            <h3
              class="text-xl tracking-tight"
              style="font-family: 'Fraunces', serif; font-weight: 600"
            >
              {{ t.name }}
            </h3>
            <p class="mt-1 text-sm text-[#1A1A1A]/55">{{ t.tagline }}</p>

            <div class="mt-6 flex items-baseline gap-1">
              <span
                class="text-5xl tracking-tight"
                style="font-family: 'Fraunces', serif; font-weight: 500"
              >
                ${{ billingYearly ? t.priceYearly : t.priceMonthly }}
              </span>
              <span class="text-sm text-[#1A1A1A]/55">/user/mo</span>
            </div>
            <p class="mt-1 text-xs text-[#1A1A1A]/45">
              {{ billingYearly ? "billed yearly" : "billed monthly" }}
            </p>

            <ul
              class="mt-7 flex-1 space-y-3 border-t border-[#1A1A1A]/8 pt-6"
            >
              <li
                v-for="f in t.features"
                :key="f"
                class="flex items-start gap-2.5 text-sm text-[#1A1A1A]/75"
              >
                <Check
                  class="mt-0.5 h-4 w-4 shrink-0 text-[#7FB069]"
                  :stroke-width="2.5"
                />
                {{ f }}
              </li>
            </ul>

            <Link
              :href="route('register')"
              class="mt-8 inline-flex w-full items-center justify-center gap-1.5 rounded-full py-3 text-sm font-medium transition-all"
              :class="
                t.highlighted
                  ? 'bg-[#1A1A1A] text-[#FAF8F3] hover:bg-[#1A1A1A]/90'
                  : 'border border-[#1A1A1A]/15 hover:border-[#1A1A1A]/30'
              "
            >
              {{ t.cta }}
              <ArrowRight class="h-3.5 w-3.5" :stroke-width="2.25" />
            </Link>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section id="faq" class="py-20 md:py-28">
      <div class="mx-auto max-w-3xl px-4 md:px-8">
        <div class="text-center">
          <p
            class="text-xs font-medium tracking-widest text-[#7FB069] uppercase"
          >
            Questions
          </p>
          <h2
            class="mt-4 text-4xl leading-[1.1] tracking-tight md:text-5xl"
            style="font-family: 'Fraunces', serif; font-weight: 500"
          >
            The real ones.
          </h2>
        </div>

        <div class="mt-12 space-y-3">
          <div
            v-for="(item, i) in faqs"
            :key="i"
            class="overflow-hidden rounded-2xl border border-[#1A1A1A]/8 bg-white"
          >
            <button
              @click="toggleFaq(i)"
              class="flex w-full items-center justify-between gap-4 p-5 text-left transition-colors hover:bg-[#FAF8F3] md:p-6"
            >
              <span class="text-[15px] font-medium md:text-base">{{ item.q }}</span>
              <div
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full transition-all"
                :class="
                  openFaq === i
                    ? 'bg-[#7FB069] text-white rotate-180'
                    : 'bg-[#FAF8F3] text-[#1A1A1A]/60'
                "
              >
                <Plus v-if="openFaq !== i" class="h-3.5 w-3.5" :stroke-width="2.25" />
                <Minus v-else class="h-3.5 w-3.5" :stroke-width="2.25" />
              </div>
            </button>
            <div
              v-if="openFaq === i"
              class="border-t border-[#1A1A1A]/8 px-5 pt-4 pb-6 text-sm leading-relaxed text-[#1A1A1A]/70 md:px-6"
            >
              {{ item.a }}
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- FINAL CTA -->
    <section class="relative overflow-hidden">
      <div
        class="absolute inset-0 bg-gradient-to-br from-[#7FB069]/15 via-[#FAF8F3] to-[#F4C7A1]/15"
      ></div>
      <div
        class="absolute top-10 left-1/4 h-72 w-72 rounded-full bg-[#7FB069]/20 blur-3xl"
      ></div>
      <div
        class="absolute right-1/4 bottom-10 h-72 w-72 rounded-full bg-[#F4C7A1]/25 blur-3xl"
      ></div>

      <div class="relative mx-auto max-w-4xl px-4 py-24 text-center md:px-8 md:py-32">
        <h2
          class="text-5xl leading-[1.05] tracking-tight md:text-7xl"
          style="font-family: 'Fraunces', serif; font-weight: 500"
        >
          Ship the
          <em class="font-light italic text-[#7FB069]">damn thing.</em>
        </h2>
        <p class="mx-auto mt-6 max-w-xl text-lg text-[#1A1A1A]/65">
          Stop fighting your tools. Start shipping. 14-day free trial, no credit card,
          no sales call.
        </p>

        <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
          <Link
            :href="route('register')"
            class="group inline-flex items-center justify-center gap-2 rounded-full bg-[#1A1A1A] px-8 py-4 text-sm font-medium text-[#FAF8F3] transition-all hover:bg-[#1A1A1A]/90 hover:shadow-xl"
          >
            Start free today
            <ArrowUpRight
              class="h-4 w-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
              :stroke-width="2.25"
            />
          </Link>
          <a
            href="#"
            class="inline-flex items-center justify-center gap-2 rounded-full border border-[#1A1A1A]/15 bg-white/70 px-8 py-4 text-sm font-medium backdrop-blur-sm transition-all hover:border-[#1A1A1A]/30"
          >
            <Github class="h-4 w-4" :stroke-width="2.25" />
            Self-host instead
          </a>
        </div>
      </div>
    </section>

    <!-- FOOTER -->
    <footer class="border-t border-[#1A1A1A]/8 bg-[#FAF8F3] py-14">
      <div class="mx-auto max-w-7xl px-4 md:px-8">
        <div class="grid gap-10 md:grid-cols-4">
          <div class="md:col-span-2">
            <div class="flex items-center gap-2.5">
              <div
                class="flex h-9 w-9 items-center justify-center rounded-lg border border-[#1A1A1A]/15 bg-[#7FB069]/15"
              >
                <Boxes class="h-4.5 w-4.5 text-[#7FB069]" :stroke-width="2.25" />
              </div>
              <span
                class="text-[17px] font-semibold tracking-tight"
                style="font-family: 'Fraunces', serif"
              >
                Sprint Sync
              </span>
            </div>
            <p class="mt-5 max-w-md text-sm leading-relaxed text-[#1A1A1A]/55">
              The all-in-one workspace for cross-functional teams who want to ship, not
              configure. Self-hostable. AI-powered. Built with care.
            </p>
          </div>
          <div>
            <h4 class="text-xs font-semibold tracking-widest text-[#1A1A1A]/50 uppercase">
              Product
            </h4>
            <ul class="mt-4 space-y-2.5 text-sm">
              <li>
                <a
                  href="#features"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Features</a
                >
              </li>
              <li>
                <a
                  href="#compare"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Compare</a
                >
              </li>
              <li>
                <a
                  href="#pricing"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Pricing</a
                >
              </li>
              <li>
                <a
                  href="#"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Changelog</a
                >
              </li>
            </ul>
          </div>
          <div>
            <h4 class="text-xs font-semibold tracking-widest text-[#1A1A1A]/50 uppercase">
              Company
            </h4>
            <ul class="mt-4 space-y-2.5 text-sm">
              <li>
                <a
                  href="#"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >About</a
                >
              </li>
              <li>
                <a
                  href="#"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Blog</a
                >
              </li>
              <li>
                <a
                  href="#"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Careers</a
                >
              </li>
              <li>
                <a
                  href="#"
                  class="text-[#1A1A1A]/65 transition-colors hover:text-[#1A1A1A]"
                  >Contact</a
                >
              </li>
            </ul>
          </div>
        </div>

        <div
          class="mt-12 flex flex-col items-start justify-between gap-4 border-t border-[#1A1A1A]/8 pt-8 md:flex-row md:items-center"
        >
          <p class="text-xs text-[#1A1A1A]/45">
            © 2026 Sprint Sync. Built for teams who'd rather ship than configure.
          </p>
          <a
            href="#"
            class="rounded-full border border-[#1A1A1A]/10 p-2 text-[#1A1A1A]/55 transition-all hover:border-[#1A1A1A]/25 hover:text-[#1A1A1A]"
          >
            <Github class="h-4 w-4" :stroke-width="2" />
          </a>
        </div>
      </div>
    </footer>
  </div>
</template>
