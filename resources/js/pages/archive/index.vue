<script setup lang="ts">
import { Archive as ArchiveIcon, CalendarClock, FolderKanban, User as UserIcon, X } from 'lucide-vue-next';

import AppDataTable, { type Column } from '@/components/ui/AppDataTable.vue';
import type { ArchiveAssigneeOption, ArchiveFilters, ArchivePage, ArchiveProjectOption, ArchiveRecord } from '@/lib/archive';
import { archiveTypeLabel, formatOccurredAt } from '@/lib/archive';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { watchDebounced } from '@vueuse/core';

const props = defineProps<{
    results: ArchivePage<ArchiveRecord>;
    filters: ArchiveFilters;
    projects: ArchiveProjectOption[];
    assignees: ArchiveAssigneeOption[];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Archive', href: workspaceRoute('workspace.archive.index') }];

const search = ref(props.filters.q);
const type = ref<string>(props.filters.type || 'all');
const projectId = ref(props.filters.project_id ? String(props.filters.project_id) : '');
const assigneeId = ref(props.filters.assignee_id ? String(props.filters.assignee_id) : '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);

const typeOptions = [
    { value: 'all', label: 'All' },
    { value: 'task', label: 'Tasks' },
    { value: 'meeting', label: 'Meetings' },
];

const hasActiveFilters = computed(
    () => search.value !== '' || type.value !== 'all' || projectId.value !== '' || assigneeId.value !== '' || from.value !== '' || to.value !== '',
);

let suppressWatch = false;

function applyFilters(page = 1) {
    if (suppressWatch) return;

    router.get(
        workspaceRoute('workspace.archive.index'),
        {
            q: search.value || undefined,
            type: type.value === 'all' ? undefined : type.value,
            project_id: projectId.value || undefined,
            assignee_id: assigneeId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watchDebounced(search, () => applyFilters(), { debounce: 350 });
watch([type, projectId, assigneeId, from, to], () => applyFilters());

function goToPage(page: number) {
    applyFilters(page);
}

function clearFilters() {
    suppressWatch = true;
    search.value = '';
    type.value = 'all';
    projectId.value = '';
    assigneeId.value = '';
    from.value = '';
    to.value = '';
    suppressWatch = false;
    applyFilters();
}

function openRecord(record: ArchiveRecord) {
    router.visit(record.url);
}

const columns: Column<ArchiveRecord>[] = [
    { key: 'type', label: 'Type', width: '150px' },
    { key: 'title', label: 'Title' },
    { key: 'project_name', label: 'Project', hideOnMobile: true, width: '200px' },
    { key: 'assignee_name', label: 'Assignee', hideOnMobile: true, width: '160px' },
    { key: 'occurred_at', label: 'Date', align: 'right', width: '140px' },
];
</script>

<template>
    <Head title="Archive" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace"
                title="Archive"
                description="Completed tasks and past meetings across every project you can access."
            />

            <div class="overflow-hidden rounded-xl border bg-card">
                <AppListToolBar
                    v-model:search="search"
                    v-model:filter="type"
                    :filter-options="typeOptions"
                    search-placeholder="Search archived tasks & meetings…"
                    search-width="sm:max-w-sm"
                >
                    <template #right>
                        <select
                            v-model="projectId"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                        >
                            <option value="">All projects</option>
                            <option v-for="project in projects" :key="project.id" :value="String(project.id)">{{ project.name }}</option>
                        </select>

                        <select
                            v-model="assigneeId"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                        >
                            <option value="">Any assignee</option>
                            <option v-for="assignee in assignees" :key="assignee.id" :value="String(assignee.id)">{{ assignee.name }}</option>
                        </select>

                        <input
                            v-model="from"
                            type="date"
                            aria-label="From date"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                        />
                        <input
                            v-model="to"
                            type="date"
                            aria-label="To date"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                        />

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="text-muted-foreground hover:text-foreground inline-flex h-9 items-center gap-1 rounded-lg px-2 text-xs font-medium transition-colors"
                            @click="clearFilters"
                        >
                            <X class="size-3.5" />
                            Clear
                        </button>
                    </template>
                </AppListToolBar>

                <AppDataTable :columns="columns" :rows="results.data" clickable-rows @row-click="openRecord">
                    <template #cell-type="{ row }">
                        <AppBadge :variant="row.type === 'task' ? 'purple' : 'info'">{{ archiveTypeLabel(row.type) }}</AppBadge>
                    </template>

                    <template #cell-title="{ row }">
                        <div class="min-w-0">
                            <p class="text-foreground truncate font-medium">{{ row.title }}</p>
                            <p v-if="row.subtitle" class="text-muted-foreground mt-0.5 truncate text-xs">{{ row.subtitle }}</p>
                        </div>
                    </template>

                    <template #cell-project_name="{ row }">
                        <span class="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
                            <FolderKanban class="size-3.5 shrink-0" />
                            <span class="truncate">{{ row.project_name }}</span>
                        </span>
                    </template>

                    <template #cell-assignee_name="{ row }">
                        <span class="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
                            <UserIcon class="size-3.5 shrink-0" />
                            <span class="truncate">{{ row.assignee_name ?? '—' }}</span>
                        </span>
                    </template>

                    <template #cell-occurred_at="{ row }">
                        <span class="text-muted-foreground inline-flex items-center justify-end gap-1.5 text-sm">
                            <CalendarClock class="size-3.5 shrink-0" />
                            {{ formatOccurredAt(row.occurred_at) }}
                        </span>
                    </template>

                    <template #empty>
                        <AppEmptyState
                            :title="hasActiveFilters ? 'No matching records' : 'Nothing archived yet'"
                            :description="
                                hasActiveFilters
                                    ? 'Try a different keyword, project, or date range.'
                                    : 'Completed tasks and past meetings will show up here automatically.'
                            "
                        >
                            <template #icon>
                                <ArchiveIcon class="size-5" />
                            </template>
                        </AppEmptyState>
                    </template>
                </AppDataTable>

                <AppPagination
                    :current-page="results.current_page"
                    :last-page="results.last_page"
                    :total="results.total"
                    :from="results.from"
                    :to="results.to"
                    @change="goToPage"
                />
            </div>
        </div>
    </AppLayout>
</template>
