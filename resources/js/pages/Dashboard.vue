<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { Activity, AlertTriangle, ArrowUpRight, CheckCircle2, FolderKanban, ListTodo, Mail, Rocket, Sparkles, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

import type { DashboardProjectSummary } from '@/components/dashboard/ProjectSummaryList.vue';
import type { TaskProgress } from '@/components/dashboard/TaskProgressCard.vue';
import type { DashboardMeeting } from '@/components/dashboard/UpcomingMeetingsCard.vue';
import type { ActivityEntry } from '@/lib/activity';
import { daysSince, formatDateEyebrow, greeting } from '@/lib/activity';
import type { Member } from '@/lib/members';

const props = defineProps<{
    user: { name: string; email: string };
    workspaceMeta: { name: string; created_at: string; plan?: string }; // ← was `workspace`
    members: Member[];
    pendingInvitesCount: number;
    activity: ActivityEntry[];
    /** Onboarding state from the backend — booleans for each milestone */
    onboarding: {
        workspace_created: boolean;
        first_member_invited: boolean;
        role_assigned: boolean;
        first_project_created: boolean;
        first_sprint_run: boolean;
    };
    upcomingMeetings: DashboardMeeting[];
    pastMeetings: DashboardMeeting[];
    taskProgress: TaskProgress;
    projects: DashboardProjectSummary[];
    scope: 'team' | 'personal';
    /** Deferred: the most serious finding across this person's projects, or null. */
    insight?: {
        severity: 'critical' | 'warning';
        headline: string;
        detail: string;
        suggestion: string | null;
        project_id: number;
        project_name: string;
    } | null;
    capabilities: {
        canInviteMembers: boolean;
        canCreateProjects: boolean;
        canManageWorkspace: boolean;
        canManageMembers: boolean;
        canManageRoles: boolean;
        canViewAnalytics: boolean;
        canViewAudit: boolean;
        permissions: string[];
    };
}>();

const isPersonalScope = computed(() => props.scope === 'personal');

const showWorkspaceOverview = computed(() => props.capabilities.canManageMembers);

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const onlineMembers = computed(() => props.members.filter((m) => m.status === 'active'));

const teamMembers = computed(() => props.members.filter((m) => m.status !== 'pending'));

const firstName = computed(() => props.user.name.split(' ')[0]);

const workspaceAge = computed(() => daysSince(props.workspaceMeta.created_at));

useDockContext('dashboard');

const onboardingSteps = computed<ChecklistStep[]>(() => [
    {
        key: 'workspace',
        title: 'Create your workspace',
        description: 'Name it, brand it, set your timezone.',
        done: props.onboarding.workspace_created,
        doneCaption: `Done ${workspaceAge.value} ${workspaceAge.value === 1 ? 'day' : 'days'} ago`,
        minutes: 1,
    },
    {
        key: 'invite',
        title: 'Invite your first teammate',
        description: 'You move faster together than alone.',
        done: props.onboarding.first_member_invited,
        doneCaption: `${teamMembers.value.length} ${teamMembers.value.length === 1 ? 'member' : 'members'} joined`,
        href: workspaceRoute('workspace.invitations.create'),
        minutes: 1,
    },
    {
        key: 'roles',
        title: 'Assign roles',
        description: 'Decide who can manage projects and billing.',
        done: props.onboarding.role_assigned,
        href: workspaceRoute('workspace.teams.index'),
        minutes: 1,
    },
    {
        key: 'project',
        title: 'Create your first project',
        description: 'Group sprints and tasks under a project.',
        done: props.onboarding.first_project_created,
        // href: workspaceRoute('workspace.projects.create'),
        minutes: 2,
    },
    {
        key: 'sprint',
        title: 'Run your first sprint',
        description: 'Plan a week, ship something small, retro it.',
        done: props.onboarding.first_sprint_run,
        // href: workspaceRoute('workspace.sprints.create'),
        minutes: 3,
    },
]);

const allDone = computed(() => onboardingSteps.value.every((s) => s.done));

const insightIsCritical = computed(() => props.insight?.severity === 'critical');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <!-- =========================================================== -->
            <!-- Hero greeting                                                 -->
            <!-- =========================================================== -->
            <!-- =========================================================== -->
            <!-- Hero band — the landing page's lavender panel, scaled down    -->
            <!-- =========================================================== -->
            <section class="dash-hero relative overflow-hidden rounded-[24px] p-5 sm:rounded-[28px] sm:p-7">
                <div class="dash-glow" aria-hidden="true"></div>

                <div class="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-[11px] font-extrabold tracking-[0.16em] uppercase opacity-45">[ {{ formatDateEyebrow() }} ]</p>

                        <h1 class="mt-3 text-[clamp(1.75rem,3.6vw,2.5rem)] leading-[1.02] font-extrabold tracking-[-0.03em]">
                            {{ greeting() }}, <span class="dash-mark">{{ firstName }}</span>
                        </h1>

                        <p class="mt-2.5 max-w-[52ch] text-[14px] leading-relaxed font-medium opacity-65">
                            Here's what's happening across
                            <span class="font-bold opacity-100">{{ workspaceMeta.name }}</span>
                            today.
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-2 text-[11.5px] font-bold dark:bg-white/10">
                            <component :is="isPersonalScope ? ListTodo : Users" class="size-3.5" />
                            {{ isPersonalScope ? 'My dashboard' : 'Team dashboard' }}
                        </span>

                        <Link
                            v-if="capabilities.canInviteMembers"
                            :href="workspaceRoute('workspace.invitations.create')"
                            class="group inline-flex items-center gap-2 rounded-full bg-[#0B0B0F] py-2 pr-2 pl-4 text-[13px] font-bold text-white transition-transform hover:-translate-y-0.5 dark:bg-white dark:text-[#0B0B0F]"
                        >
                            Invite teammate
                            <span
                                class="grid size-6 place-items-center rounded-full bg-[#BAFF1A] transition-transform duration-300 group-hover:rotate-45"
                            >
                                <ArrowUpRight class="size-3.5 text-[#0B0B0F]" :stroke-width="3" />
                            </span>
                        </Link>
                    </div>
                </div>
            </section>

            <!-- =========================================================== -->
            <!-- What the assistant noticed — one finding, computed server-side -->
            <!-- =========================================================== -->
            <Deferred data="insight">
                <template #fallback>
                    <div class="bg-card animate-pulse rounded-2xl border p-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-muted size-9 rounded-xl"></div>
                            <div class="flex-1 space-y-2">
                                <div class="bg-muted h-3 w-52 rounded"></div>
                                <div class="bg-muted h-3 w-80 max-w-full rounded"></div>
                            </div>
                        </div>
                    </div>
                </template>

                <div
                    v-if="insight"
                    class="relative overflow-hidden rounded-2xl border p-4 sm:p-5"
                    :class="
                        insightIsCritical
                            ? 'via-card to-card border-rose-500/30 bg-linear-to-br from-rose-500/[0.07]'
                            : 'via-card to-card border-amber-500/30 bg-linear-to-br from-amber-400/[0.09]'
                    "
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-xl"
                                :class="insightIsCritical ? 'bg-rose-500/15 text-rose-500' : 'bg-lime-400/25 text-lime-700 dark:text-lime-300'"
                            >
                                <Sparkles class="size-4" />
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-[14px] font-bold tracking-tight">{{ insight.headline }}</p>
                                    <AppBadge :variant="insightIsCritical ? 'danger' : 'warning'" size="sm">
                                        {{ insight.project_name }}
                                    </AppBadge>
                                </div>
                                <p class="text-muted-foreground mt-1 text-[12.5px] leading-relaxed">{{ insight.detail }}</p>
                                <p v-if="insight.suggestion" class="mt-1 text-[12.5px] leading-relaxed font-medium">
                                    {{ insight.suggestion }}
                                </p>
                            </div>
                        </div>

                        <Button v-if="capabilities.canViewAnalytics" as-child size="sm" variant="outline" class="shrink-0 gap-1.5 rounded-full">
                            <Link :href="workspaceRoute('workspace.analytics.index')">
                                See the breakdown
                                <ArrowUpRight class="size-3.5" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </Deferred>

            <!-- =========================================================== -->
            <!-- Stat cards — every number is real, no fake data               -->
            <!-- =========================================================== -->
            <div v-if="!showWorkspaceOverview" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatCard
                    tone="lavender"
                    :label="isPersonalScope ? 'My tasks' : 'Team tasks'"
                    :value="taskProgress.total"
                    :hint="taskProgress.total === 1 ? 'assigned' : 'assigned'"
                >
                    <template #icon><ListTodo class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard tone="lime" label="Completed" :value="taskProgress.completed" :hint="`${taskProgress.completion_percentage}% done`">
                    <template #icon><CheckCircle2 class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard tone="ink" label="Overdue" :value="taskProgress.overdue" :hint="taskProgress.overdue === 1 ? 'task' : 'tasks'">
                    <template #icon><AlertTriangle class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard tone="indigo" label="Projects" :value="projects.length" :hint="projects.length === 1 ? 'assigned' : 'assigned'">
                    <template #icon><FolderKanban class="size-3.5" /></template>
                </AppStatCard>
            </div>

            <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatCard tone="lavender" label="Team" :value="teamMembers.length" :hint="teamMembers.length === 1 ? 'member' : 'members'">
                    <template #icon><Users class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard tone="lime" label="Online" :value="onlineMembers.length" hint="right now">
                    <template #icon>
                        <Activity class="size-3.5" />
                    </template>
                </AppStatCard>

                <AppStatCard tone="ink" label="Pending" :value="pendingInvitesCount" :hint="pendingInvitesCount === 1 ? 'invite' : 'invites'">
                    <template #icon><Mail class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard tone="indigo" label="Workspace age" :value="workspaceAge" :hint="workspaceAge === 1 ? 'day old' : 'days old'">
                    <template #icon><Rocket class="size-3.5" /></template>
                </AppStatCard>
            </div>

            <!-- =========================================================== -->
            <!-- Main grid: checklist + activity (left) | sidebar (right)      -->
            <!-- =========================================================== -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- LEFT: 2/3 width on lg -->
                <div class="flex flex-col gap-4 lg:col-span-2">
                    <TaskProgressCard :progress="taskProgress" />

                    <ProjectSummaryList :projects="projects" />

                    <OnBoardingCheckList v-if="capabilities.canManageWorkspace" :steps="onboardingSteps" />

                    <!-- Activity feed -->
                    <div v-if="showWorkspaceOverview" class="bg-card rounded-2xl border p-5 shadow-sm sm:p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-[15px] font-semibold tracking-tight">Recent workspace activity</h3>
                            <button type="button" class="text-muted-foreground hover:text-foreground text-[11.5px] font-medium transition-colors">
                                View all
                            </button>
                        </div>

                        <ActivityTimeLine v-if="activity.length > 0" :entries="activity" />
                        <div v-else class="text-muted-foreground py-8 text-center text-xs">No activity yet — invite a teammate to get started.</div>
                    </div>
                </div>

                <!-- RIGHT: 1/3 width on lg -->
                <div class="flex flex-col gap-4">
                    <UpcomingMeetingsCard :upcoming="upcomingMeetings" :past="pastMeetings" />

                    <OnlineNowCard v-if="showWorkspaceOverview" :members="onlineMembers" :view-all-href="workspaceRoute('workspace.teams.index')" />

                    <!-- Tip card — small, only shows when relevant -->
                    <div v-if="capabilities.canManageWorkspace && !allDone" class="bg-muted/20 rounded-2xl border border-dashed p-4 text-xs">
                        <p class="text-foreground font-medium">💡 Pro tip</p>
                        <p class="text-muted-foreground mt-1 leading-relaxed">
                            Press
                            <kbd class="border-border bg-background rounded border px-1 py-0.5 font-mono text-[10px]">⌘K</kbd>
                            anywhere to jump between teammates, projects, and settings without leaving your keyboard.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/*
 * A scaled-down version of the landing page's lavender hero panel. The landing
 * page forces light; the dashboard has a real dark mode, so every colour here
 * needs both halves rather than being pinned to one.
 */
.dash-hero {
    background: #e4e3ff;
    color: #0b0b0f;
}

:global(.dark) .dash-hero {
    background: color-mix(in oklab, var(--color-card) 82%, #365aff 18%);
    color: var(--color-foreground);
}

/* Lime and indigo bleeding in from opposite corners, as on the landing hero. */
.dash-glow {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(45% 65% at 92% 6%, rgba(54, 90, 255, 0.16), transparent 70%),
        radial-gradient(38% 55% at 2% 98%, rgba(186, 255, 26, 0.3), transparent 70%);
}

/* The lime swipe under the first name. */
.dash-mark {
    position: relative;
    display: inline-block;
}

.dash-mark::after {
    content: '';
    position: absolute;
    inset: auto -0.1em 0.06em;
    height: 0.32em;
    border-radius: 3px;
    background: #baff1a;
    z-index: -1;
}
</style>
