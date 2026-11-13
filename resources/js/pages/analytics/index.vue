<script setup lang="ts">
import { AlertTriangle, CalendarClock, ChevronDown, CircleCheck, CircleDashed, Eye, FolderKanban, OctagonAlert, User, Users } from 'lucide-vue-next';
import type { Component } from 'vue';

import type { AppSelectOption } from '@/components/ui/AppSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Analytics, AnalyticsFilters, AnalyticsProjectOption, AnalyticsSprintOption, ProjectSummary } from '@/lib/analytics';
import { type HealthVerdict, type ProjectHealth, rankFindings } from '@/lib/health';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    analytics: Analytics;
    filters: AnalyticsFilters;
    projects: AnalyticsProjectOption[];
    sprints: AnalyticsSprintOption[];
    /** Deferred: each assessment costs queries, and the totals are useful first. */
    health?: ProjectHealth[];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Analytics', href: workspaceRoute('workspace.analytics.index') }];

const projectId = ref(props.filters.project_id ? String(props.filters.project_id) : '');
const sprintId = ref(props.filters.sprint_id ? String(props.filters.sprint_id) : '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters() {
    router.get(
        workspaceRoute('workspace.analytics.index'),
        {
            project_id: projectId.value || undefined,
            sprint_id: sprintId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([projectId, sprintId, from, to], () => applyFilters());
watch(projectId, () => (sprintId.value = ''));

const projectOptions = computed<AppSelectOption<string>[]>(() => [
    { value: '', label: 'All projects' },
    ...props.projects.map((project) => ({ value: String(project.id), label: project.name })),
]);
const sprintOptions = computed<AppSelectOption<string>[]>(() => [
    { value: '', label: 'Current sprint' },
    ...props.sprints
        .filter((sprint) => projectId.value === '' || String(sprint.project_id) === projectId.value)
        .map((sprint) => ({ value: String(sprint.id), label: sprint.name, meta: sprint.is_current ? 'Current' : undefined })),
]);

const selectedProjectName = computed(() => props.projects.find((project) => String(project.id) === projectId.value)?.name ?? null);
const selectedHealth = computed(() =>
    projectId.value ? (props.health ?? []).find((item) => String(item.project_id) === projectId.value) : undefined,
);
const meetingRangeActive = computed(() => from.value !== '' || to.value !== '');

function clearMeetingRange() {
    from.value = '';
    to.value = '';
}

const isPersonalScope = computed(() => props.analytics.scope === 'personal');

const VERDICTS: Record<HealthVerdict, { icon: Component; pill: string; rank: number }> = {
    critical: { icon: OctagonAlert, pill: 'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300', rank: 0 },
    at_risk: { icon: AlertTriangle, pill: 'bg-amber-500/10 text-amber-800 ring-amber-500/25 dark:text-amber-300', rank: 1 },
    watch: { icon: Eye, pill: 'bg-sky-500/10 text-sky-800 ring-sky-500/20 dark:text-sky-300', rank: 2 },
    healthy: { icon: CircleCheck, pill: 'bg-emerald-500/10 text-emerald-800 ring-emerald-500/20 dark:text-emerald-300', rank: 3 },
    no_data: { icon: CircleDashed, pill: 'bg-muted text-muted-foreground ring-border', rank: 4 },
};

const healthLoaded = computed(() => props.health !== undefined);
const healthById = computed(() => new Map((props.health ?? []).map((item) => [item.project_id, item])));
const needsAttention = computed(() =>
    (props.health ?? [])
        .filter((item) => item.verdict === 'critical' || item.verdict === 'at_risk')
        .sort((a, b) => VERDICTS[a.verdict].rank - VERDICTS[b.verdict].rank),
);
const assessedCount = computed(() => (props.health ?? []).filter((item) => item.verdict !== 'no_data').length);

type ProjectRow = ProjectSummary & { health: ProjectHealth | null };

const emptyProjects = computed(() => props.analytics.projects.filter((project) => project.total_tasks === 0));
const projectRows = computed<ProjectRow[]>(() =>
    props.analytics.projects
        .filter((project) => project.total_tasks > 0)
        .map((project) => ({ ...project, health: healthById.value.get(project.id) ?? null }))
        .sort((a, b) => {
            const rank = (row: ProjectRow) => (row.total_tasks === 0 ? 9 : row.health ? VERDICTS[row.health.verdict].rank : 5);

            return rank(a) - rank(b) || b.total_tasks - a.total_tasks || a.name.localeCompare(b.name);
        }),
);

const expanded = ref<Set<number>>(new Set());

function toggleRow(id: number) {
    const next = new Set(expanded.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    expanded.value = next;
}

const overdueShare = computed(() =>
    props.analytics.open_tasks ? Math.round((props.analytics.overdue_tasks / props.analytics.open_tasks) * 100) : 0,
);

const sprint = computed(() => props.analytics.sprint_progress);
const sprintTitle = computed(() => {
    const sprints = sprint.value.sprints;

    if (sprints.length === 1) return `${sprints[0].name} · ${sprints[0].project_name}`;

    return `${sprints.length} active sprints`;
});
const sprintTimeline = computed(() => {
    const sprints = sprint.value.sprints;

    if (sprints.length !== 1) return null;

    const day = 24 * 60 * 60 * 1000;
    const start = new Date(`${sprints[0].starts_on}T00:00:00`).getTime();
    const end = new Date(`${sprints[0].ends_on}T00:00:00`).getTime();
    const total = Math.max(1, Math.round((end - start) / day) + 1);
    const elapsed = Math.min(total, Math.max(0, Math.floor((Date.now() - start) / day) + 1));

    return { day: elapsed, total, left: total - elapsed, pace: Math.round((elapsed / total) * 100) };
});
const sprintBehind = computed(() => (sprintTimeline.value ? sprint.value.completion_percentage + 10 < sprintTimeline.value.pace : false));

const share = (count: number, total: number) => (total ? Math.round((count / total) * 100) : 0);

const workflowTotal = computed(() => props.analytics.tasks_by_column.reduce((sum, column) => sum + column.count, 0));
const sprintColumnsTotal = computed(() => sprint.value.tasks_by_column.reduce((sum, column) => sum + column.count, 0));

const assignees = computed(() => {
    const people = props.analytics.tasks_by_assignee.filter((entry) => entry.assignee_id !== null);
    const unassigned = props.analytics.tasks_by_assignee.find((entry) => entry.assignee_id === null);

    return unassigned ? [...people, unassigned] : people;
});
const assigneeMax = computed(() => Math.max(1, ...assignees.value.map((entry) => entry.count)));
const assigneeTotal = computed(() => assignees.value.reduce((sum, entry) => sum + entry.count, 0));

const formatCount = (value: number) => value.toLocaleString();
</script>

<template>
    <Head title="Analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24 lg:p-8 lg:pb-24">
            <AppPageHeader
                title="Analytics"
                :description="
                    isPersonalScope
                        ? 'How the work assigned to you is going across your projects.'
                        : 'How work is going across every project you can see, and where it needs you.'
                "
            >
                <template #actions>
                    <span
                        class="text-muted-foreground inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium"
                        data-testid="analytics-scope"
                    >
                        <component :is="isPersonalScope ? User : Users" class="size-3.5" aria-hidden="true" />
                        {{ isPersonalScope ? 'Your work only' : 'Whole team' }}
                    </span>
                </template>
            </AppPageHeader>

            <div class="flex flex-wrap items-center gap-2" data-testid="analytics-filters">
                <AppSelect
                    v-model="projectId"
                    :options="projectOptions"
                    size="sm"
                    class="w-56"
                    aria-label="Project"
                    search-placeholder="Search projects…"
                />
                <AppSelect
                    v-if="sprintOptions.length > 1"
                    v-model="sprintId"
                    :options="sprintOptions"
                    size="sm"
                    class="w-48"
                    aria-label="Sprint"
                    search-placeholder="Search sprints…"
                />
                <button
                    v-if="projectId"
                    type="button"
                    class="text-muted-foreground hover:text-foreground inline-flex h-8 items-center rounded-md px-2 text-sm"
                    @click="projectId = ''"
                >
                    Clear
                </button>
                <p class="text-muted-foreground ml-auto hidden text-xs sm:block">
                    {{ selectedProjectName ? `Showing ${selectedProjectName}` : `Showing all ${analytics.total_projects} projects` }}
                </p>
            </div>

            <section aria-labelledby="attention-heading" data-testid="attention">
                <h2 id="attention-heading" class="sr-only">Needs attention</h2>
                <div v-if="!healthLoaded" class="bg-card flex h-[68px] items-center gap-3 rounded-xl border px-4" aria-busy="true">
                    <span class="bg-muted size-5 animate-pulse rounded-full motion-reduce:animate-none" />
                    <span class="bg-muted h-3.5 w-72 animate-pulse rounded motion-reduce:animate-none" />
                </div>
                <div v-else-if="needsAttention.length" class="bg-card overflow-hidden rounded-xl border">
                    <div class="flex items-center gap-2 border-b px-4 py-2.5 text-sm">
                        <OctagonAlert class="size-4 text-rose-600 dark:text-rose-400" aria-hidden="true" />
                        <span class="font-medium">
                            {{ needsAttention.length }} {{ needsAttention.length === 1 ? 'project needs' : 'projects need' }} attention
                        </span>
                        <span class="text-muted-foreground">· {{ assessedCount }} assessed</span>
                    </div>
                    <ul class="divide-y">
                        <li v-for="item in needsAttention.slice(0, 3)" :key="item.project_id">
                            <a
                                :href="`#project-${item.project_id}`"
                                class="hover:bg-muted/40 flex flex-col gap-1 px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:gap-3"
                                @click="expanded = new Set([...expanded, item.project_id])"
                            >
                                <span class="flex min-w-0 items-center gap-2 sm:w-64 sm:shrink-0">
                                    <span
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="VERDICTS[item.verdict].pill"
                                    >
                                        <component :is="VERDICTS[item.verdict].icon" class="size-3" aria-hidden="true" />
                                        {{ item.verdict_label }}
                                    </span>
                                    <span class="truncate font-medium">{{ item.project_name }}</span>
                                </span>
                                <span class="text-muted-foreground min-w-0 truncate">{{ rankFindings(item.signals)[0]?.headline }}</span>
                            </a>
                        </li>
                        <li v-if="needsAttention.length > 3">
                            <a href="#projects-heading" class="text-muted-foreground hover:text-foreground block px-4 py-2.5 text-sm">
                                See all {{ needsAttention.length }} in Projects ↓
                            </a>
                        </li>
                    </ul>
                </div>
                <p v-else class="bg-card flex items-center gap-2 rounded-xl border px-4 py-3 text-sm">
                    <CircleCheck class="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                    <span v-if="assessedCount">Nothing needs attention. All {{ assessedCount }} assessed projects look on track.</span>
                    <span v-else class="text-muted-foreground">No project has tasks yet, so there is nothing to assess.</span>
                </p>
            </section>

            <section
                aria-label="Key numbers"
                class="bg-border grid grid-cols-2 gap-px overflow-hidden rounded-xl border lg:grid-cols-4"
                data-testid="kpis"
            >
                <div class="bg-card p-4 sm:p-5">
                    <p class="text-muted-foreground text-sm">Completion</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ analytics.task_completion_percentage }}%</p>
                    <div
                        class="bg-muted mt-3 h-1.5 overflow-hidden rounded-full"
                        role="meter"
                        :aria-valuenow="analytics.task_completion_percentage"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="Tasks completed"
                    >
                        <div class="h-full rounded-full bg-emerald-600" :style="{ width: `${analytics.task_completion_percentage}%` }" />
                    </div>
                    <p class="text-muted-foreground mt-2 text-xs">
                        {{ formatCount(analytics.completed_tasks) }} of {{ formatCount(analytics.total_tasks) }} tasks done
                    </p>
                </div>
                <div class="bg-card p-4 sm:p-5">
                    <p class="text-muted-foreground text-sm">Open tasks</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ formatCount(analytics.open_tasks) }}</p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        {{
                            selectedProjectName
                                ? `In ${selectedProjectName}`
                                : `Across ${analytics.total_projects} ${analytics.total_projects === 1 ? 'project' : 'projects'}`
                        }}
                    </p>
                </div>
                <div class="bg-card p-4 sm:p-5">
                    <p class="text-muted-foreground flex items-center gap-1.5 text-sm">
                        Overdue
                        <AlertTriangle v-if="analytics.overdue_tasks" class="size-3.5 text-rose-600 dark:text-rose-400" aria-hidden="true" />
                    </p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight" :class="analytics.overdue_tasks && 'text-rose-700 dark:text-rose-300'">
                        {{ formatCount(analytics.overdue_tasks) }}
                    </p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        {{ analytics.overdue_tasks ? `${overdueShare}% of open work is past its date` : 'Nothing is past its date' }}
                    </p>
                </div>
                <div v-if="selectedProjectName" class="bg-card p-4 sm:p-5">
                    <p class="text-muted-foreground text-sm">Project status</p>
                    <template v-if="selectedHealth">
                        <p class="mt-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium ring-1 ring-inset"
                                :class="VERDICTS[selectedHealth.verdict].pill"
                            >
                                <component :is="VERDICTS[selectedHealth.verdict].icon" class="size-4" aria-hidden="true" />
                                {{ selectedHealth.verdict_label }}
                            </span>
                        </p>
                        <p class="text-muted-foreground mt-2 truncate text-xs">
                            {{ rankFindings(selectedHealth.signals)[0]?.headline ?? 'No problems found' }}
                        </p>
                    </template>
                    <span v-else-if="!healthLoaded" class="bg-muted mt-2 block h-7 w-32 animate-pulse rounded-md motion-reduce:animate-none" />
                    <p v-else class="text-muted-foreground mt-2 text-sm">Not assessed</p>
                </div>
                <div v-else class="bg-card p-4 sm:p-5">
                    <p class="text-muted-foreground text-sm">Projects</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight">{{ formatCount(analytics.total_projects) }}</p>
                    <p class="mt-2 text-xs">
                        <span v-if="!healthLoaded" class="bg-muted inline-block h-3 w-28 animate-pulse rounded motion-reduce:animate-none" />
                        <span v-else-if="needsAttention.length" class="text-rose-700 dark:text-rose-300"
                            >{{ needsAttention.length }} need attention</span
                        >
                        <span v-else class="text-muted-foreground">None need attention</span>
                    </p>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-5">
                <section class="bg-card rounded-xl border p-5 lg:col-span-3" aria-labelledby="sprint-heading" data-testid="sprint">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h2 id="sprint-heading" class="text-sm font-semibold">Sprint progress</h2>
                        <p v-if="sprint.has_sprint" class="text-muted-foreground text-xs">{{ sprintTitle }}</p>
                    </div>
                    <template v-if="sprint.has_sprint">
                        <div class="mt-4 flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                            <p class="text-3xl font-semibold tracking-tight">
                                {{ sprint.completion_percentage }}%
                                <span class="text-muted-foreground text-sm font-normal">
                                    · {{ sprint.completed_tasks }} of {{ sprint.total_tasks }} done
                                </span>
                            </p>
                            <p
                                v-if="sprintTimeline"
                                class="text-sm"
                                :class="sprintBehind ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground'"
                            >
                                <span v-if="sprintBehind" class="inline-flex items-center gap-1">
                                    <AlertTriangle class="size-3.5" aria-hidden="true" />
                                    Behind pace ·
                                </span>
                                Day {{ sprintTimeline.day }} of {{ sprintTimeline.total }} · {{ sprintTimeline.left }}
                                {{ sprintTimeline.left === 1 ? 'day' : 'days' }} left
                            </p>
                        </div>
                        <div class="relative mt-3">
                            <div
                                class="bg-muted h-2 overflow-hidden rounded-full"
                                role="meter"
                                :aria-valuenow="sprint.completion_percentage"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="Sprint tasks completed"
                            >
                                <div class="h-full rounded-full bg-emerald-600" :style="{ width: `${sprint.completion_percentage}%` }" />
                            </div>
                            <span
                                v-if="sprintTimeline"
                                class="bg-foreground absolute -top-1 h-4 w-0.5 rounded-full"
                                :style="{ left: `calc(${sprintTimeline.pace}% - 1px)` }"
                                :title="`Expected by today: ${sprintTimeline.pace}%`"
                                aria-hidden="true"
                            />
                        </div>
                        <p v-if="sprintTimeline" class="text-muted-foreground mt-1.5 text-xs">The marker shows where today falls in the sprint.</p>
                        <ul class="mt-5 grid gap-2.5" aria-label="Sprint tasks by column">
                            <li
                                v-for="column in sprint.tasks_by_column"
                                :key="column.name"
                                class="grid grid-cols-[7rem_1fr_3.5rem] items-center gap-3 text-sm"
                            >
                                <span class="truncate">{{ column.name }}</span>
                                <span class="bg-muted/60 h-2 overflow-hidden rounded-r-full">
                                    <span
                                        class="block h-full rounded-r-full"
                                        :class="column.is_done ? 'bg-emerald-600' : 'bg-indigo-600 dark:bg-indigo-500'"
                                        :style="{ width: `${share(column.count, sprintColumnsTotal)}%` }"
                                    />
                                </span>
                                <span class="text-right tabular-nums">{{ column.count }}</span>
                            </li>
                        </ul>
                    </template>
                    <div v-else class="mt-4 flex items-start gap-3 text-sm">
                        <CalendarClock class="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p class="text-muted-foreground">
                            No sprint is running{{ selectedProjectName ? ` in ${selectedProjectName}` : '' }}. Start one from a project's board to
                            track progress against its dates here.
                        </p>
                    </div>
                </section>

                <section class="bg-card rounded-xl border p-5 lg:col-span-2" aria-labelledby="workflow-heading" data-testid="workflow">
                    <div class="flex items-baseline justify-between gap-4">
                        <h2 id="workflow-heading" class="text-sm font-semibold">Where tasks are</h2>
                        <p class="text-muted-foreground text-xs">{{ formatCount(workflowTotal) }} tasks</p>
                    </div>
                    <ul v-if="analytics.tasks_by_column.length" class="mt-4 grid gap-3.5" aria-label="Tasks by workflow column">
                        <li v-for="column in analytics.tasks_by_column" :key="column.name" class="grid gap-1.5 text-sm">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="size-2 shrink-0 rounded-sm"
                                        :class="column.is_done ? 'bg-emerald-600' : 'bg-indigo-600 dark:bg-indigo-500'"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{ column.name }}</span>
                                </span>
                                <span class="tabular-nums">
                                    {{ formatCount(column.count) }}
                                    <span class="text-muted-foreground ml-1 text-xs">{{ share(column.count, workflowTotal) }}%</span>
                                </span>
                            </span>
                            <span class="bg-muted/60 h-1.5 overflow-hidden rounded-r-full">
                                <span
                                    class="block h-full rounded-r-full"
                                    :class="column.is_done ? 'bg-emerald-600' : 'bg-indigo-600 dark:bg-indigo-500'"
                                    :style="{ width: `${share(column.count, workflowTotal)}%` }"
                                />
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-4 text-sm">No tasks yet.</p>
                </section>
            </div>

            <section aria-labelledby="projects-heading" data-testid="projects">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2 id="projects-heading" class="text-sm font-semibold">Projects</h2>
                    <p class="text-muted-foreground text-xs">Ordered by what needs you first. Open a row for the reasons and who holds the work.</p>
                </div>
                <div class="bg-card overflow-hidden rounded-xl border">
                    <div
                        class="text-muted-foreground hidden grid-cols-[minmax(0,2.2fr)_9rem_minmax(0,1.3fr)_4.5rem_4.5rem_4.5rem_2rem] gap-4 border-b px-4 py-2.5 text-xs font-medium md:grid"
                        aria-hidden="true"
                    >
                        <span>Project</span>
                        <span>Status</span>
                        <span>Progress</span>
                        <span class="text-right">Open</span>
                        <span class="text-right">Overdue</span>
                        <span class="text-right">Unowned</span>
                        <span />
                    </div>
                    <AppEmptyState
                        v-if="!analytics.projects.length"
                        compact
                        title="No projects yet"
                        description="Create a project to see how its work is going."
                    >
                        <template #icon><FolderKanban class="size-5" /></template>
                    </AppEmptyState>
                    <ul v-else-if="projectRows.length" class="divide-y">
                        <li v-for="row in projectRows" :id="`project-${row.id}`" :key="row.id" class="scroll-mt-6">
                            <button
                                type="button"
                                class="hover:bg-muted/40 focus-visible:bg-muted/60 grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 px-4 py-3 text-left text-sm outline-none md:grid-cols-[minmax(0,2.2fr)_9rem_minmax(0,1.3fr)_4.5rem_4.5rem_4.5rem_2rem] md:items-center"
                                :aria-expanded="expanded.has(row.id)"
                                :aria-controls="`project-detail-${row.id}`"
                                :disabled="!row.health || row.total_tasks === 0"
                                @click="toggleRow(row.id)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ row.name }}</span>
                                    <span
                                        v-if="row.health && rankFindings(row.health.signals)[0]"
                                        class="text-muted-foreground block truncate text-xs"
                                    >
                                        {{ rankFindings(row.health.signals)[0].headline
                                        }}<template v-if="row.health.signals.length > 1"> · +{{ row.health.signals.length - 1 }} more</template>
                                    </span>
                                    <span v-else-if="row.total_tasks === 0" class="text-muted-foreground block text-xs">No tasks yet</span>
                                </span>
                                <span class="justify-self-end md:justify-self-start">
                                    <span
                                        v-if="row.health"
                                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
                                        :class="VERDICTS[row.health.verdict].pill"
                                    >
                                        <component :is="VERDICTS[row.health.verdict].icon" class="size-3" aria-hidden="true" />
                                        {{ row.health.verdict_label }}
                                    </span>
                                    <span
                                        v-else-if="!healthLoaded"
                                        class="bg-muted block h-5 w-24 animate-pulse rounded-md motion-reduce:animate-none"
                                    />
                                    <span v-else class="text-muted-foreground text-xs">Not assessed</span>
                                </span>
                                <span class="col-span-2 flex items-center gap-2 md:col-span-1">
                                    <span class="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                        <span class="block h-full rounded-full bg-emerald-600" :style="{ width: `${row.completion_percentage}%` }" />
                                    </span>
                                    <span class="text-muted-foreground w-24 shrink-0 text-right text-xs tabular-nums md:w-10">
                                        <span class="md:hidden">{{ row.completed_tasks }} of {{ row.total_tasks }} · </span
                                        >{{ row.completion_percentage }}%
                                    </span>
                                </span>
                                <span class="text-muted-foreground col-span-2 flex gap-4 text-xs md:contents md:text-sm">
                                    <span class="md:text-foreground tabular-nums md:text-right">
                                        <span class="md:hidden">Open </span
                                        >{{ row.health ? row.health.open_tasks : row.total_tasks - row.completed_tasks }}
                                    </span>
                                    <span
                                        class="tabular-nums md:text-right"
                                        :class="row.health?.overdue_tasks ? 'font-medium text-rose-700 dark:text-rose-300' : 'md:text-foreground'"
                                    >
                                        <span class="md:hidden">Overdue </span>{{ row.health ? row.health.overdue_tasks : '–' }}
                                    </span>
                                    <span
                                        class="tabular-nums md:text-right"
                                        :class="
                                            row.health?.unassigned_open_tasks
                                                ? 'font-medium text-amber-800 dark:text-amber-300'
                                                : 'md:text-foreground'
                                        "
                                    >
                                        <span class="md:hidden">Unowned </span>{{ row.health ? row.health.unassigned_open_tasks : '–' }}
                                    </span>
                                </span>
                                <ChevronDown
                                    v-if="row.health && row.total_tasks"
                                    class="text-muted-foreground hidden size-4 justify-self-end transition-transform md:block"
                                    :class="expanded.has(row.id) && 'rotate-180'"
                                    aria-hidden="true"
                                />
                            </button>
                            <div
                                v-if="row.health && expanded.has(row.id)"
                                :id="`project-detail-${row.id}`"
                                class="bg-muted/20 grid gap-6 border-t px-4 py-4 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
                            >
                                <div>
                                    <h3 class="text-muted-foreground mb-2 text-xs font-medium">Why</h3>
                                    <ul v-if="row.health.signals.length" class="grid gap-3">
                                        <li v-for="finding in rankFindings(row.health.signals)" :key="finding.code" class="flex gap-2.5 text-sm">
                                            <component
                                                :is="
                                                    finding.severity === 'critical'
                                                        ? OctagonAlert
                                                        : finding.severity === 'warning'
                                                          ? AlertTriangle
                                                          : CircleDashed
                                                "
                                                class="mt-0.5 size-4 shrink-0"
                                                :class="
                                                    finding.severity === 'critical'
                                                        ? 'text-rose-600 dark:text-rose-400'
                                                        : finding.severity === 'warning'
                                                          ? 'text-amber-600 dark:text-amber-400'
                                                          : 'text-muted-foreground'
                                                "
                                                aria-hidden="true"
                                            />
                                            <span class="min-w-0">
                                                <span class="block font-medium">{{ finding.headline }}</span>
                                                <span class="text-muted-foreground block">{{ finding.detail }}</span>
                                                <span v-if="finding.suggestion" class="mt-0.5 block">→ {{ finding.suggestion }}</span>
                                            </span>
                                        </li>
                                    </ul>
                                    <p v-else class="text-muted-foreground text-sm">No problems found.</p>
                                </div>
                                <div>
                                    <h3 class="text-muted-foreground mb-2 text-xs font-medium">Open work by person</h3>
                                    <ul class="grid gap-2">
                                        <li
                                            v-for="person in row.health.workload.filter((entry) => entry.open_tasks > 0)"
                                            :key="person.name"
                                            class="grid grid-cols-[7rem_1fr_4.5rem] items-center gap-3 text-sm"
                                        >
                                            <span class="truncate" :class="person.user_id === null && 'text-muted-foreground'">{{
                                                person.name
                                            }}</span>
                                            <span class="bg-muted/70 h-1.5 overflow-hidden rounded-r-full">
                                                <span
                                                    class="block h-full rounded-r-full"
                                                    :class="person.user_id === null ? 'bg-muted-foreground/40' : 'bg-indigo-600 dark:bg-indigo-500'"
                                                    :style="{ width: `${Math.max(person.share_percentage, 3)}%` }"
                                                />
                                            </span>
                                            <span class="text-right text-xs tabular-nums">
                                                {{ person.open_tasks }} <span class="text-muted-foreground">· {{ person.share_percentage }}%</span>
                                            </span>
                                        </li>
                                    </ul>
                                    <p v-if="!row.health.workload.some((entry) => entry.open_tasks > 0)" class="text-muted-foreground text-sm">
                                        No open work.
                                    </p>
                                </div>
                            </div>
                        </li>
                    </ul>
                    <p v-if="emptyProjects.length" class="text-muted-foreground border-t px-4 py-3 text-xs" data-testid="empty-projects">
                        No tasks yet in {{ emptyProjects.length }} {{ emptyProjects.length === 1 ? 'project' : 'projects' }}:
                        {{ emptyProjects.map((project) => project.name).join(', ') }}.
                    </p>
                </div>
                <p v-if="healthLoaded && (health ?? []).length < projectRows.length" class="text-muted-foreground mt-2 text-xs">
                    Status is worked out for up to {{ (health ?? []).length }} projects at a time. Pick one project above to see its status.
                </p>
            </section>

            <div class="grid gap-6" :class="!isPersonalScope && 'lg:grid-cols-2'">
                <section v-if="!isPersonalScope" class="bg-card rounded-xl border p-5" aria-labelledby="people-heading" data-testid="workload">
                    <div class="flex items-baseline justify-between gap-4">
                        <h2 id="people-heading" class="text-sm font-semibold">Tasks assigned</h2>
                        <p class="text-muted-foreground text-xs">All statuses · {{ formatCount(assigneeTotal) }} tasks</p>
                    </div>
                    <ul v-if="assignees.length" class="mt-4 grid gap-2.5" aria-label="Tasks by assignee">
                        <li
                            v-for="entry in assignees"
                            :key="entry.assignee_id ?? 'unassigned'"
                            class="grid grid-cols-[minmax(0,9rem)_1fr_5rem] items-center gap-3 text-sm"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <AppAvatar v-if="entry.assignee_id !== null" :name="entry.name" size="xs" class="shrink-0" />
                                <span
                                    v-else
                                    class="border-muted-foreground/40 size-6 shrink-0 rounded-full border border-dashed"
                                    aria-hidden="true"
                                />
                                <span class="truncate" :class="entry.assignee_id === null && 'text-muted-foreground'">{{ entry.name }}</span>
                            </span>
                            <span class="bg-muted/60 h-2 overflow-hidden rounded-r-full">
                                <span
                                    class="block h-full rounded-r-full"
                                    :class="entry.assignee_id === null ? 'bg-muted-foreground/40' : 'bg-indigo-600 dark:bg-indigo-500'"
                                    :style="{ width: `${(entry.count / assigneeMax) * 100}%` }"
                                />
                            </span>
                            <span class="text-right tabular-nums">
                                {{ entry.count }} <span class="text-muted-foreground text-xs">{{ share(entry.count, assigneeTotal) }}%</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground mt-4 text-sm">No tasks are assigned yet.</p>
                </section>

                <section class="bg-card rounded-xl border p-5" aria-labelledby="meetings-heading" data-testid="meetings">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="meetings-heading" class="text-sm font-semibold">Meetings</h2>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <label class="sr-only" for="meetings-from">Meetings from</label>
                            <input
                                id="meetings-from"
                                v-model="from"
                                type="date"
                                class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-8 rounded-md border px-2 text-sm outline-none focus-visible:ring-[3px]"
                            />
                            <span class="text-muted-foreground">to</span>
                            <label class="sr-only" for="meetings-to">Meetings to</label>
                            <input
                                id="meetings-to"
                                v-model="to"
                                type="date"
                                class="bg-background focus-visible:border-ring focus-visible:ring-ring/25 h-8 rounded-md border px-2 text-sm outline-none focus-visible:ring-[3px]"
                            />
                            <button
                                v-if="meetingRangeActive"
                                type="button"
                                class="text-muted-foreground hover:text-foreground h-8 rounded-md px-2"
                                @click="clearMeetingRange"
                            >
                                All dates
                            </button>
                        </div>
                    </div>
                    <dl class="mt-5 grid grid-cols-3 divide-x rounded-lg border">
                        <div class="px-4 py-3">
                            <dt class="text-muted-foreground text-xs">Upcoming</dt>
                            <dd class="mt-0.5 text-2xl font-semibold tracking-tight">{{ formatCount(analytics.upcoming_meetings) }}</dd>
                        </div>
                        <div class="px-4 py-3">
                            <dt class="text-muted-foreground text-xs">Held</dt>
                            <dd class="mt-0.5 text-2xl font-semibold tracking-tight">{{ formatCount(analytics.past_meetings) }}</dd>
                        </div>
                        <div class="px-4 py-3">
                            <dt class="text-muted-foreground text-xs">Total</dt>
                            <dd class="mt-0.5 text-2xl font-semibold tracking-tight">{{ formatCount(analytics.total_meetings) }}</dd>
                        </div>
                    </dl>
                    <p class="text-muted-foreground mt-3 text-xs">
                        {{ meetingRangeActive ? 'Counts cover the chosen dates only.' : 'Counts cover all dates. Pick dates to narrow them.' }}
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
