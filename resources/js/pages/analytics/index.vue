<script setup lang="ts">
import { AlertTriangle, CalendarClock, CheckCircle2, ClipboardList, FolderKanban, ListTodo, User, Users } from 'lucide-vue-next';

import AppDataTable, { type Column } from '@/components/ui/AppDataTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Analytics, AnalyticsFilters, AnalyticsProjectOption, AnalyticsSprintOption, ProjectSummary } from '@/lib/analytics';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    analytics: Analytics;
    filters: AnalyticsFilters;
    projects: AnalyticsProjectOption[];
    sprints: AnalyticsSprintOption[];
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

const hasActiveFilters = computed(() => projectId.value !== '' || sprintId.value !== '' || from.value !== '' || to.value !== '');

function clearFilters() {
    projectId.value = '';
    sprintId.value = '';
    from.value = '';
    to.value = '';
}

const columnBars = computed(() =>
    props.analytics.tasks_by_column.map((col) => ({
        label: col.name,
        count: col.count,
        tone: col.is_done ? ('success' as const) : ('default' as const),
    })),
);

const assigneeBars = computed(() => props.analytics.tasks_by_assignee.map((a) => ({ label: a.name, count: a.count })));

const sprintProgress = computed(() => props.analytics.sprint_progress);

const sprintBars = computed(() =>
    sprintProgress.value.tasks_by_column.map((col) => ({
        label: col.name,
        count: col.count,
        tone: col.is_done ? ('success' as const) : ('default' as const),
    })),
);

const sprintHeading = computed(() => {
    const sprints = sprintProgress.value.sprints;
    if (sprints.length === 0) return 'Current sprint';
    if (sprints.length === 1) return sprints[0].name;
    return `${sprints.length} active sprints`;
});

const sprintSelectOptions = computed(() => props.sprints.filter((sprint) => projectId.value === '' || String(sprint.project_id) === projectId.value));

const isPersonalScope = computed(() => props.analytics.scope === 'personal');

const projectColumns: Column<ProjectSummary>[] = [
    { key: 'name', label: 'Project' },
    { key: 'total_tasks', label: 'Tasks', align: 'right', width: '90px' },
    { key: 'completed_tasks', label: 'Completed', align: 'right', width: '110px' },
    { key: 'completion_percentage', label: 'Completion', width: '180px' },
];
</script>

<template>
    <Head title="Analytics" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace"
                title="Analytics"
                :description="
                    isPersonalScope
                        ? 'How your own assigned work is progressing across the projects you belong to.'
                        : 'How work is progressing across every project you can access.'
                "
            >
                <template #actions>
                    <span
                        class="border-border bg-muted/40 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium"
                    >
                        <component :is="isPersonalScope ? User : Users" class="size-3" />
                        {{ isPersonalScope ? 'My analytics' : 'Team analytics' }}
                    </span>
                </template>
            </AppPageHeader>

            <div class="bg-card flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center">
                <select
                    v-model="projectId"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                >
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="String(project.id)">{{ project.name }}</option>
                </select>

                <select
                    v-if="sprintSelectOptions.length > 0"
                    v-model="sprintId"
                    aria-label="Sprint"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                >
                    <option value="">Current sprint</option>
                    <option v-for="sprint in sprintSelectOptions" :key="sprint.id" :value="String(sprint.id)">
                        {{ sprint.name }}{{ sprint.is_current ? ' (current)' : '' }}
                    </option>
                </select>

                <div class="flex items-center gap-2">
                    <input
                        v-model="from"
                        type="date"
                        aria-label="Meetings from date"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                    />
                    <span class="text-muted-foreground text-xs">to</span>
                    <input
                        v-model="to"
                        type="date"
                        aria-label="Meetings to date"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                    />
                </div>

                <p class="text-muted-foreground text-xs">Date range applies to meetings only.</p>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-muted-foreground hover:text-foreground text-xs font-medium transition-colors sm:ml-auto"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <AppStatCard label="Projects" :value="analytics.total_projects">
                    <template #icon><FolderKanban class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard label="Total tasks" :value="analytics.total_tasks">
                    <template #icon><ListTodo class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard label="Completed" :value="analytics.completed_tasks" :hint="`${analytics.task_completion_percentage}%`">
                    <template #icon><CheckCircle2 class="size-3.5 text-emerald-500" /></template>
                </AppStatCard>

                <AppStatCard label="Open" :value="analytics.open_tasks">
                    <template #icon><ClipboardList class="size-3.5" /></template>
                </AppStatCard>

                <AppStatCard
                    label="Overdue"
                    :value="analytics.overdue_tasks"
                    :hint="analytics.overdue_tasks > 0 ? 'needs attention' : undefined"
                    :trend="analytics.overdue_tasks > 0 ? 'down' : null"
                >
                    <template #icon><AlertTriangle class="size-3.5" :class="analytics.overdue_tasks > 0 && 'text-amber-500'" /></template>
                </AppStatCard>

                <AppStatCard label="Meetings" :value="analytics.total_meetings" :hint="`${analytics.upcoming_meetings} upcoming`">
                    <template #icon><CalendarClock class="size-3.5" /></template>
                </AppStatCard>
            </div>

            <div class="bg-card rounded-xl border p-5 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h3 class="text-[15px] font-semibold tracking-tight">Sprint task completion</h3>
                        <span
                            v-if="sprintProgress.has_sprint"
                            class="border-border bg-muted/40 text-muted-foreground rounded-full border px-2 py-0.5 text-[11px] font-medium"
                        >
                            {{ sprintHeading }}
                        </span>
                    </div>

                    <span v-if="sprintProgress.has_sprint" class="text-muted-foreground text-xs tabular-nums">
                        {{ sprintProgress.completed_tasks }} of {{ sprintProgress.total_tasks }} done
                    </span>
                </div>

                <template v-if="sprintProgress.has_sprint">
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-3xl font-semibold tracking-tight tabular-nums"> {{ sprintProgress.completion_percentage }}% </span>
                        <span class="text-muted-foreground text-xs tabular-nums">{{ sprintProgress.open_tasks }} open</span>
                    </div>

                    <div class="bg-muted mt-3 h-2 w-full overflow-hidden rounded-full">
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all"
                            :style="{ width: `${sprintProgress.completion_percentage}%` }"
                        />
                    </div>

                    <div class="mt-5">
                        <AppBarList :items="sprintBars" empty-label="No tasks in this sprint yet." />
                    </div>
                </template>

                <p v-else class="text-muted-foreground mt-4 text-xs">
                    No sprint is running right now. Create a sprint on a project and assign tasks to it to see sprint progress here.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="bg-card rounded-xl border p-5 shadow-sm lg:col-span-1">
                    <h3 class="text-[15px] font-semibold tracking-tight">Task completion</h3>

                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-3xl font-semibold tracking-tight tabular-nums">{{ analytics.task_completion_percentage }}%</span>
                        <span class="text-muted-foreground text-xs tabular-nums">
                            {{ analytics.completed_tasks }} of {{ analytics.total_tasks }}
                        </span>
                    </div>

                    <div class="bg-muted mt-3 h-2 w-full overflow-hidden rounded-full">
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all"
                            :style="{ width: `${analytics.task_completion_percentage}%` }"
                        />
                    </div>

                    <div class="mt-6 grid grid-cols-3 gap-2 border-t pt-4 text-center">
                        <div>
                            <p class="text-lg font-semibold tabular-nums">{{ analytics.total_meetings }}</p>
                            <p class="text-muted-foreground text-[11px] tracking-wide uppercase">Meetings</p>
                        </div>
                        <div>
                            <p class="text-lg font-semibold tabular-nums">{{ analytics.upcoming_meetings }}</p>
                            <p class="text-muted-foreground text-[11px] tracking-wide uppercase">Upcoming</p>
                        </div>
                        <div>
                            <p class="text-lg font-semibold tabular-nums">{{ analytics.past_meetings }}</p>
                            <p class="text-muted-foreground text-[11px] tracking-wide uppercase">Past</p>
                        </div>
                    </div>
                </div>

                <div class="bg-card rounded-xl border p-5 shadow-sm lg:col-span-1">
                    <h3 class="text-[15px] font-semibold tracking-tight">Tasks by workflow column</h3>
                    <div class="mt-4">
                        <AppBarList :items="columnBars" empty-label="No tasks yet." />
                    </div>
                </div>

                <div v-if="!isPersonalScope" class="bg-card rounded-xl border p-5 shadow-sm lg:col-span-1">
                    <div class="flex items-center gap-1.5">
                        <Users class="text-muted-foreground size-3.5" />
                        <h3 class="text-[15px] font-semibold tracking-tight">Tasks by assignee</h3>
                    </div>
                    <div class="mt-4">
                        <AppBarList :items="assigneeBars" empty-label="No tasks assigned yet." />
                    </div>
                </div>
            </div>

            <div class="bg-card overflow-hidden rounded-xl border shadow-sm">
                <div class="border-b px-5 py-4">
                    <h3 class="text-[15px] font-semibold tracking-tight">Project performance</h3>
                </div>

                <AppDataTable :columns="projectColumns" :rows="analytics.projects" row-key="id">
                    <template #cell-completion_percentage="{ row }">
                        <div class="flex items-center gap-2">
                            <div class="bg-muted h-1.5 w-24 overflow-hidden rounded-full">
                                <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${row.completion_percentage}%` }" />
                            </div>
                            <span class="text-muted-foreground text-xs tabular-nums">{{ row.completion_percentage }}%</span>
                        </div>
                    </template>

                    <template #empty>
                        <AppEmptyState title="No projects yet" description="Project performance will show up here once you have accessible projects.">
                            <template #icon>
                                <FolderKanban class="size-5" />
                            </template>
                        </AppEmptyState>
                    </template>
                </AppDataTable>
            </div>
        </div>
    </AppLayout>
</template>
