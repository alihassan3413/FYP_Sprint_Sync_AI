<script setup lang="ts">
import { Activity, Command, Mail, Rocket, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

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
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

/* ----------------------------------------------------------------------------
 * Derived data — never trust the backend to compute display-only things
 * ------------------------------------------------------------------------- */
const onlineMembers = computed(() => props.members.filter((m) => m.status === 'active'));

const teamMembers = computed(() => props.members.filter((m) => m.status !== 'pending'));

const firstName = computed(() => props.user.name.split(' ')[0]);

const workspaceAge = computed(() => daysSince(props.workspaceMeta.created_at));

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
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <!-- =========================================================== -->
            <!-- Hero greeting                                                 -->
            <!-- =========================================================== -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.08em] uppercase">
                        {{ formatDateEyebrow() }}
                    </p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-[28px]">{{ greeting() }}, {{ firstName }}.</h1>
                    <p class="text-muted-foreground mt-1 text-sm">
                        Here's what's happening across
                        <span class="text-foreground font-medium">{{ workspaceMeta.name }}</span>
                        today.
                    </p>
                </div>

                <button
                    type="button"
                    class="border-border bg-card hover:bg-muted/40 inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-medium transition-colors"
                >
                    <Command class="size-3.5" />
                    Quick actions
                    <kbd class="border-border bg-background text-muted-foreground ml-1 rounded border px-1.5 py-0.5 font-mono text-[10px]"> ⌘K </kbd>
                </button>
            </div>

            <!-- =========================================================== -->
            <!-- Stat cards — every number is real, no fake data               -->
            <!-- =========================================================== -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <AppStatCard label="Team" :value="teamMembers.length" :hint="teamMembers.length === 1 ? 'member' : 'members'">
                    <template #icon><Users class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard label="Online" :value="onlineMembers.length" hint="right now">
                    <template #icon>
                        <Activity class="size-3.5 text-emerald-500" />
                    </template>
                </AppStatCard>

                <AppStatCard label="Pending" :value="pendingInvitesCount" :hint="pendingInvitesCount === 1 ? 'invite' : 'invites'">
                    <template #icon><Mail class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard label="Workspace age" :value="workspaceAge" :hint="workspaceAge === 1 ? 'day old' : 'days old'">
                    <template #icon><Rocket class="size-3.5" /></template>
                </AppStatCard>
            </div>

            <!-- =========================================================== -->
            <!-- Main grid: checklist + activity (left) | sidebar (right)      -->
            <!-- =========================================================== -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <!-- LEFT: 2/3 width on lg -->
                <div class="flex flex-col gap-4 lg:col-span-2">
                    <OnBoardingCheckList :steps="onboardingSteps" />

                    <!-- Activity feed -->
                    <div class="bg-card rounded-xl border p-5 shadow-sm sm:p-6">
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
                    <ComingNextCard
                        title="AI sprint planning"
                        description="Once you create projects, SprintSync will draft sprint plans from your tasks and team capacity."
                        cta-label="Join the waitlist"
                    />

                    <OnlineNowCard :members="onlineMembers" :view-all-href="workspaceRoute('workspace.teams.index')" />

                    <!-- Tip card — small, only shows when relevant -->
                    <div v-if="!allDone" class="bg-muted/20 rounded-xl border border-dashed p-4 text-xs">
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
