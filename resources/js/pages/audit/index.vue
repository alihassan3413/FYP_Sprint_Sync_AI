<script setup lang="ts">
import { CalendarClock, FolderKanban, ScrollText } from 'lucide-vue-next';

import AppDataTable, { type Column } from '@/components/ui/AppDataTable.vue';
import type { AuditActorOption, AuditFilters, AuditLogEntry, AuditPage, AuditProjectOption } from '@/lib/audit';
import { categoryBadgeVariant, formatAuditTimestamp } from '@/lib/audit';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    entries: AuditPage<AuditLogEntry>;
    filters: AuditFilters;
    projects: AuditProjectOption[];
    actors: AuditActorOption[];
    categories: string[];
    isAdmin: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: workspaceRoute('workspace.settings') },
    { title: 'Audit Log', href: workspaceRoute('workspace.audit.index') },
];

const userId = ref(props.filters.user_id ? String(props.filters.user_id) : '');
const category = ref(props.filters.category || '');
const projectId = ref(props.filters.project_id ? String(props.filters.project_id) : '');
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters(page = 1) {
    router.get(
        workspaceRoute('workspace.audit.index'),
        {
            user_id: userId.value || undefined,
            category: category.value || undefined,
            project_id: projectId.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch([userId, category, projectId, from, to], () => applyFilters());

function goToPage(page: number) {
    applyFilters(page);
}

const hasActiveFilters = computed(
    () => userId.value !== '' || category.value !== '' || projectId.value !== '' || from.value !== '' || to.value !== '',
);

function clearFilters() {
    userId.value = '';
    category.value = '';
    projectId.value = '';
    from.value = '';
    to.value = '';
}

const columns: Column<AuditLogEntry>[] = [
    { key: 'actor_name', label: 'Actor', width: '180px' },
    { key: 'category', label: 'Category', width: '120px' },
    { key: 'description', label: 'Activity' },
    { key: 'project_name', label: 'Project', hideOnMobile: true, width: '180px' },
    { key: 'created_at', label: 'When', align: 'right', width: '170px' },
];
</script>

<template>
    <Head title="Audit Log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace"
                title="Audit Log"
                :description="
                    isAdmin
                        ? 'A chronological record of administrative and project activity across the workspace.'
                        : 'A chronological record of activity for the projects you manage.'
                "
            />

            <div class="flex flex-col gap-3 rounded-xl border bg-card px-4 py-3 sm:flex-row sm:items-center">
                <select
                    v-model="category"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                >
                    <option value="">All categories</option>
                    <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                </select>

                <select
                    v-model="userId"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                >
                    <option value="">Anyone</option>
                    <option v-for="actor in actors" :key="actor.id" :value="String(actor.id)">{{ actor.name }}</option>
                </select>

                <select
                    v-if="isAdmin"
                    v-model="projectId"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                >
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="String(project.id)">{{ project.name }}</option>
                </select>

                <div class="flex items-center gap-2">
                    <input
                        v-model="from"
                        type="date"
                        aria-label="From date"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                    />
                    <span class="text-muted-foreground text-xs">to</span>
                    <input
                        v-model="to"
                        type="date"
                        aria-label="To date"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-2.5 text-sm transition-colors focus:ring-2 focus:outline-none"
                    />
                </div>

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="text-muted-foreground hover:text-foreground sm:ml-auto text-xs font-medium transition-colors"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card">
                <AppDataTable :columns="columns" :rows="entries.data">
                    <template #cell-actor_name="{ row }">
                        <div class="flex items-center gap-2">
                            <AppAvatar :name="row.actor_name ?? 'System'" :src="row.actor_avatar_url" size="xs" />
                            <span class="text-foreground truncate text-sm">{{ row.actor_name ?? 'System' }}</span>
                        </div>
                    </template>

                    <template #cell-category="{ row }">
                        <AppBadge :variant="categoryBadgeVariant(row.category)">{{ row.category }}</AppBadge>
                    </template>

                    <template #cell-description="{ row }">
                        <p class="text-foreground text-sm">{{ row.description }}</p>
                        <p class="text-muted-foreground mt-0.5 text-xs">{{ row.action_label }}</p>
                    </template>

                    <template #cell-project_name="{ row }">
                        <span class="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
                            <FolderKanban class="size-3.5 shrink-0" />
                            <span class="truncate">{{ row.project_name ?? 'Workspace' }}</span>
                        </span>
                    </template>

                    <template #cell-created_at="{ row }">
                        <span class="text-muted-foreground inline-flex items-center justify-end gap-1.5 text-sm">
                            <CalendarClock class="size-3.5 shrink-0" />
                            {{ formatAuditTimestamp(row.created_at) }}
                        </span>
                    </template>

                    <template #empty>
                        <AppEmptyState
                            :title="hasActiveFilters ? 'No matching activity' : 'No activity yet'"
                            :description="
                                hasActiveFilters
                                    ? 'Try a different user, category, project, or date range.'
                                    : 'Administrative and project activity will show up here as it happens.'
                            "
                        >
                            <template #icon>
                                <ScrollText class="size-5" />
                            </template>
                        </AppEmptyState>
                    </template>
                </AppDataTable>

                <AppPagination
                    :current-page="entries.current_page"
                    :last-page="entries.last_page"
                    :total="entries.total"
                    :from="entries.from"
                    :to="entries.to"
                    @change="goToPage"
                />
            </div>
        </div>
    </AppLayout>
</template>
