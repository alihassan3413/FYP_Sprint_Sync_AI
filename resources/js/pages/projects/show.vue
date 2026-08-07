<script setup lang="ts">
import { CalendarClock, FolderKanban, Pencil, RefreshCw, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import type { Project } from '@/lib/projects';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    project: Project;
    canManageProjects: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Projects', href: workspaceRoute('workspace.projects.index') },
    { title: props.project.name, href: workspaceRoute('workspace.projects.show', { project: props.project.id }) },
]);

useDockContext('projects');

const isEditModalOpen = ref(false);
const isDeleteDialogOpen = ref(false);

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
}

function onDeleted() {
    router.visit(workspaceRoute('workspace.projects.index'));
}
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Project" :title="project.name" :description="project.description ?? undefined">
                <template v-if="canManageProjects" #actions>
                    <Button variant="outline" size="sm" class="gap-1.5" @click="isEditModalOpen = true">
                        <Pencil class="size-3.5" />
                        Edit
                    </Button>
                    <Button variant="outline" size="sm" class="text-destructive hover:text-destructive gap-1.5" @click="isDeleteDialogOpen = true">
                        <Trash2 class="size-3.5" />
                        Delete
                    </Button>
                </template>
            </AppPageHeader>

            <div class="bg-card rounded-xl border p-5 shadow-sm sm:p-6">
                <div class="mb-4 flex items-center gap-2 text-sm font-medium">
                    <FolderKanban class="text-muted-foreground size-4" />
                    Overview
                </div>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Description</dt>
                        <dd class="text-foreground mt-1 text-sm leading-relaxed">
                            {{ project.description || 'No description yet.' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase">
                            <CalendarClock class="size-3" />
                            Created
                        </dt>
                        <dd class="text-foreground mt-1 text-sm">{{ formatDate(project.created_at) }}</dd>
                    </div>

                    <div v-if="project.updated_at !== project.created_at">
                        <dt class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase">
                            <RefreshCw class="size-3" />
                            Last updated
                        </dt>
                        <dd class="text-foreground mt-1 text-sm">{{ formatDate(project.updated_at) }}</dd>
                    </div>
                </dl>
            </div>

            <AppEmptyState title="Tasks are coming soon" description="Task boards for this project will show up here once they ship.">
                <template #icon>
                    <FolderKanban class="size-5" />
                </template>
            </AppEmptyState>
        </div>
    </AppLayout>

    <EditProjectModal :open="isEditModalOpen" :project="project" @update:open="(value) => (isEditModalOpen = value)" />

    <DeleteProjectDialog :open="isDeleteDialogOpen" :project="project" @update:open="(value) => (isDeleteDialogOpen = value)" @deleted="onDeleted" />
</template>
