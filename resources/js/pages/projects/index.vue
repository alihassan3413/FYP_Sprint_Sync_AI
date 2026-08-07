<script setup lang="ts">
import { FolderKanban, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import type { Project } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';
import type { Column, SortState } from '@/components/ui/AppDataTable.vue';

const props = defineProps<{
    projects: Project[];
    canManageProjects: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: workspaceRoute('workspace.projects.index') }];

useDockContext('projects');

const search = ref('');
const sort = ref<SortState>({ key: 'created_at', direction: 'desc' });

const isCreateModalOpen = ref(false);
const editTarget = ref<Project | null>(null);
const deleteTarget = ref<Project | null>(null);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.projects;

    return props.projects.filter((p) => p.name.toLowerCase().includes(q) || (p.description ?? '').toLowerCase().includes(q));
});

const columns: Column<Project>[] = [
    { key: 'name', label: 'Project', sortable: true },
    { key: 'description', label: 'Description', hideOnMobile: true },
    { key: 'created_at', label: 'Created', sortable: true, width: '140px', hideOnMobile: true },
    { key: 'actions', label: '', align: 'right', width: '56px' },
];

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function openProject(project: Project) {
    router.visit(workspaceRoute('workspace.projects.show', { project: project.id }));
}
</script>

<template>
    <Head title="Projects" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Workspace" title="Projects" description="Group your team's work into projects.">
                <template v-if="canManageProjects" #actions>
                    <Button size="sm" class="gap-1.5" @click="isCreateModalOpen = true">
                        <Plus class="size-3.5" />
                        New project
                    </Button>
                </template>
            </AppPageHeader>

            <div class="bg-card overflow-hidden rounded-xl border shadow-sm">
                <AppListToolBar v-model:search="search" search-placeholder="Search projects…" />

                <AppDataTable
                    v-model:sort="sort"
                    :columns="columns"
                    :rows="filtered"
                    row-key="id"
                    clickable-rows
                    @row-click="openProject"
                >
                    <template #cell-name="{ row }">
                        <span class="text-foreground font-medium">{{ row.name }}</span>
                    </template>

                    <template #cell-description="{ value }">
                        <span class="text-muted-foreground line-clamp-1">{{ value || '—' }}</span>
                    </template>

                    <template #cell-created_at="{ value }">
                        <span class="text-muted-foreground text-xs tabular-nums">{{ formatDate(value) }}</span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div v-if="canManageProjects" @click.stop>
                            <ProjectActionsMenu
                                :project="row"
                                :can-manage="canManageProjects"
                                @edit="(project) => (editTarget = project)"
                                @delete="(project) => (deleteTarget = project)"
                            />
                        </div>
                    </template>

                    <template #empty>
                        <AppEmptyState
                            :title="search ? 'No projects match your search' : 'No projects yet'"
                            :description="
                                search
                                    ? 'Try a different search term.'
                                    : canManageProjects
                                      ? 'Create your first project to start organizing your team’s work.'
                                      : 'Nobody has created a project in this workspace yet.'
                            "
                        >
                            <template #icon>
                                <FolderKanban class="size-5" />
                            </template>

                            <template v-if="canManageProjects" #actions>
                                <Button v-if="search" variant="outline" size="sm" @click="search = ''"> Clear search </Button>
                                <Button v-else size="sm" @click="isCreateModalOpen = true"> Create your first project </Button>
                            </template>
                        </AppEmptyState>
                    </template>

                    <template #table-footer="{ rows }">
                        <div class="text-muted-foreground flex items-center justify-between px-4 py-2.5 text-xs">
                            <p class="tabular-nums">
                                Showing <span class="text-foreground font-medium">{{ rows.length }}</span> of
                                <span class="text-foreground font-medium">{{ projects.length }}</span>
                                {{ projects.length === 1 ? 'project' : 'projects' }}
                            </p>
                        </div>
                    </template>
                </AppDataTable>
            </div>
        </div>
    </AppLayout>

    <CreateProjectModal v-model:open="isCreateModalOpen" />

    <EditProjectModal :open="editTarget !== null" :project="editTarget" @update:open="(value) => !value && (editTarget = null)" />

    <DeleteProjectDialog :open="deleteTarget !== null" :project="deleteTarget" @update:open="(value) => !value && (deleteTarget = null)" />
</template>
