<script setup lang="ts">
import { CalendarClock, ChevronRight, FolderKanban, ListChecks, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatShortDate } from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';

type Row = {
    id: number;
    title: string;
    project: { id: number; name: string };
    column: { name: string; is_done: boolean };
    assignee: { id: number; name: string } | null;
    sprint: string | null;
    due_date: string | null;
    is_overdue: boolean;
    url: string;
};

type Filters = {
    scope: 'mine' | 'all';
    project: number | null;
    assignee: string | null;
    status: 'open' | 'done' | 'all';
    due: 'any' | 'overdue' | 'today' | 'week' | 'none';
    search: string | null;
};

const props = defineProps<{
    tasks: { data: Row[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null };
    filters: Filters;
    canViewAll: boolean;
    projects: { id: number; name: string }[];
    assignees: { id: number; name: string }[];
    today: string;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'My Work', href: workspaceRoute('workspace.my-work.index') }];

const state = reactive({
    scope: props.filters.scope,
    project: props.filters.project === null ? '' : String(props.filters.project),
    assignee: props.filters.assignee ?? '',
    status: props.filters.status,
    due: props.filters.due,
    search: props.filters.search ?? '',
});

function load(page?: number) {
    const query: Record<string, string | number> = { scope: state.scope, status: state.status, due: state.due };
    if (state.project) query.project = state.project;
    if (state.scope === 'all' && state.assignee) query.assignee = state.assignee;
    if (state.search.trim()) query.search = state.search.trim();
    if (page && page > 1) query.page = page;

    router.get(workspaceRoute('workspace.my-work.index'), query, { preserveScroll: true, preserveState: true, replace: true });
}

watch(
    () => [state.scope, state.project, state.assignee, state.status, state.due],
    () => load(),
);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => state.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => load(), 300);
    },
);

const groups = computed(() => {
    const map = new Map<number, { project: Row['project']; tasks: Row[] }>();
    for (const task of props.tasks.data) {
        if (!map.has(task.project.id)) map.set(task.project.id, { project: task.project, tasks: [] });
        map.get(task.project.id)!.tasks.push(task);
    }
    return [...map.values()];
});

const scopeOptions = [
    { value: 'mine', label: 'Assigned to me' },
    { value: 'all', label: 'All tasks' },
];
</script>

<template>
    <Head title="My Work" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Work" title="My Work" description="Your tasks from every project you are part of, in one place." />

            <div class="flex flex-col gap-3" data-testid="my-work-filters">
                <AppSegmentedControl v-if="canViewAll" v-model="state.scope" :options="scopeOptions" class="self-start" />
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <label
                        class="border-input bg-background focus-within:ring-ring/40 flex h-9 min-w-[14rem] flex-1 items-center gap-2 rounded-md border px-2.5 focus-within:ring-2 sm:flex-none"
                    >
                        <Search class="text-muted-foreground size-4" />
                        <input
                            v-model="state.search"
                            type="search"
                            placeholder="Search tasks…"
                            class="w-full bg-transparent outline-none"
                            aria-label="Search tasks"
                            data-testid="my-work-search"
                        />
                    </label>
                    <select
                        v-model="state.project"
                        class="bg-background h-9 rounded-md border px-2"
                        aria-label="Project"
                        data-testid="my-work-project"
                    >
                        <option value="">All projects</option>
                        <option v-for="project in projects" :key="project.id" :value="String(project.id)">{{ project.name }}</option>
                    </select>
                    <select
                        v-if="state.scope === 'all'"
                        v-model="state.assignee"
                        class="bg-background h-9 rounded-md border px-2"
                        aria-label="Assignee"
                        data-testid="my-work-assignee"
                    >
                        <option value="">Anyone</option>
                        <option value="unassigned">Unassigned</option>
                        <option v-for="person in assignees" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
                    </select>
                    <select v-model="state.status" class="bg-background h-9 rounded-md border px-2" aria-label="Status" data-testid="my-work-status">
                        <option value="open">Open</option>
                        <option value="done">Done</option>
                        <option value="all">Any status</option>
                    </select>
                    <select v-model="state.due" class="bg-background h-9 rounded-md border px-2" aria-label="Due date" data-testid="my-work-due">
                        <option value="any">Any due date</option>
                        <option value="overdue">Overdue</option>
                        <option value="today">Due today</option>
                        <option value="week">Due in 7 days</option>
                        <option value="none">No due date</option>
                    </select>
                </div>
            </div>

            <div v-if="!tasks.data.length" class="bg-card rounded-2xl border">
                <AppEmptyState
                    title="Nothing here"
                    :description="state.scope === 'mine' ? 'No tasks assigned to you match these filters.' : 'No tasks match these filters.'"
                >
                    <template #icon><ListChecks class="size-5" /></template>
                </AppEmptyState>
            </div>

            <section
                v-for="group in groups"
                :key="group.project.id"
                class="bg-card overflow-hidden rounded-2xl border"
                :data-testid="`my-work-project-${group.project.id}`"
            >
                <header class="bg-muted/40 flex items-center gap-2 border-b px-4 py-2.5 text-sm font-semibold">
                    <FolderKanban class="text-muted-foreground size-4" />
                    {{ group.project.name }}
                    <span class="text-muted-foreground font-normal">· {{ group.tasks.length }}</span>
                </header>
                <ul class="divide-y">
                    <li v-for="task in group.tasks" :key="task.id">
                        <Link
                            :href="task.url"
                            class="group hover:bg-muted/40 focus-visible:ring-ring flex items-center gap-4 px-4 py-3 outline-none focus-visible:ring-2 focus-visible:ring-inset"
                            :data-testid="`my-work-task-${task.id}`"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium" :class="task.column.is_done && 'text-muted-foreground line-through'">
                                    {{ task.title }}
                                </p>
                                <p class="text-muted-foreground truncate text-xs">
                                    {{ group.project.name }} · {{ task.column.name }}<template v-if="task.sprint"> · {{ task.sprint }}</template
                                    ><template v-if="filters.scope === 'all'"> · {{ task.assignee?.name ?? 'Unassigned' }}</template>
                                </p>
                            </div>
                            <span
                                v-if="task.due_date"
                                class="inline-flex shrink-0 items-center gap-1 text-xs tabular-nums"
                                :class="
                                    task.is_overdue
                                        ? 'text-destructive font-medium'
                                        : task.due_date === today
                                          ? 'text-amber-600'
                                          : 'text-muted-foreground'
                                "
                            >
                                <CalendarClock class="size-3.5" /> {{ task.is_overdue ? 'Overdue · ' : '' }}{{ formatShortDate(task.due_date) }}
                            </span>
                            <ChevronRight class="text-muted-foreground/60 group-hover:text-foreground size-4 shrink-0" />
                        </Link>
                    </li>
                </ul>
            </section>

            <AppPagination
                v-if="tasks.last_page > 1"
                :current-page="tasks.current_page"
                :last-page="tasks.last_page"
                :total="tasks.total"
                :from="tasks.from"
                :to="tasks.to"
                @change="load"
            />
        </div>
    </AppLayout>
</template>
