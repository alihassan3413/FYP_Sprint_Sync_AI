<script setup lang="ts">
import { Activity, CalendarClock, FolderKanban, Pencil, Plus, Settings, Trash2, Users, Video } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import type { Project } from '@/lib/projects';
import type { Task, TaskMember } from '@/lib/tasks';
import { type BreadcrumbItem, type SharedData } from '@/types';

const props = defineProps<{
    project: Project;
    canManageProjects: boolean;
    canManageTasks: boolean;
    tasks: Task[];
    members: TaskMember[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const page = usePage<SharedData>();

const currentUserId = computed(() => page.props.auth.user.id);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Projects', href: workspaceRoute('workspace.projects.index') },
    { title: props.project.name, href: workspaceRoute('workspace.projects.show', { project: props.project.id }) },
]);

const shortDescription = computed(() => {
    const text = props.project.description?.trim();
    if (!text) return undefined;
    return text.length > 140 ? `${text.slice(0, 140).trimEnd()}…` : text;
});

useDockContext('projects');

const isEditModalOpen = ref(false);
const isDeleteDialogOpen = ref(false);

const isCreateTaskModalOpen = ref(false);
const taskModalTarget = ref<Task | null>(null);
const deleteTaskTarget = ref<Task | null>(null);

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function onDeleted() {
    router.visit(workspaceRoute('workspace.projects.index'));
}
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col">
            <!-- Compact header -->
            <div class="border-b px-4 py-4 md:px-6 lg:px-8">
                <AppPageHeader eyebrow="Project" :title="project.name" :description="shortDescription" />
            </div>

            <!-- Tabs -->
            <div class="flex-1 px-4 py-5 md:px-6 lg:px-8">
                <Tabs default-value="board">
                    <TabsList>
                        <TabsTrigger value="board">Board</TabsTrigger>
                        <TabsTrigger value="meetings">Meetings</TabsTrigger>
                        <TabsTrigger value="activity">Activity</TabsTrigger>
                        <TabsTrigger value="settings">Settings</TabsTrigger>
                    </TabsList>

                    <!-- Board -->
                    <TabsContent value="board">
                        <div class="mb-4 flex items-center justify-between">
                            <p class="text-muted-foreground text-xs">{{ tasks.length }} {{ tasks.length === 1 ? 'task' : 'tasks' }}</p>

                            <Button v-if="canManageTasks" size="sm" class="gap-1.5" @click="isCreateTaskModalOpen = true">
                                <Plus class="size-3.5" />
                                New task
                            </Button>
                        </div>

                        <KanbanBoard
                            v-if="tasks.length > 0 || canManageTasks"
                            :tasks="tasks"
                            :current-user-id="currentUserId"
                            :can-manage-tasks="canManageTasks"
                            @edit="(task) => (taskModalTarget = task)"
                            @delete="(task) => (deleteTaskTarget = task)"
                        />

                        <AppEmptyState v-else title="No tasks yet" description="Nobody has added a task to this project yet.">
                            <template #icon>
                                <FolderKanban class="size-5" />
                            </template>
                        </AppEmptyState>
                    </TabsContent>

                    <!-- Meetings (placeholder) -->
                    <TabsContent value="meetings">
                        <AppEmptyState title="Meetings are coming soon" description="Schedule and join project meetings from here once this ships.">
                            <template #icon>
                                <Video class="size-5" />
                            </template>
                        </AppEmptyState>
                    </TabsContent>

                    <!-- Activity (placeholder) -->
                    <TabsContent value="activity">
                        <AppEmptyState title="Activity is coming soon" description="A timeline of everything that happens in this project will show up here.">
                            <template #icon>
                                <Activity class="size-5" />
                            </template>
                        </AppEmptyState>
                    </TabsContent>

                    <!-- Settings -->
                    <TabsContent value="settings" class="max-w-2xl">
                        <div class="flex flex-col gap-4">
                            <div class="bg-card rounded-xl border p-5 shadow-sm">
                                <div class="mb-4 flex items-center gap-2 text-sm font-medium">
                                    <Settings class="text-muted-foreground size-4" />
                                    Project details
                                </div>

                                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <dt class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Description</dt>
                                        <dd class="text-foreground mt-1 text-sm leading-relaxed">{{ project.description || 'No description yet.' }}</dd>
                                    </div>

                                    <div>
                                        <dt class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase">
                                            <CalendarClock class="size-3" />
                                            Created
                                        </dt>
                                        <dd class="text-foreground mt-1 text-sm">{{ formatDate(project.created_at) }}</dd>
                                    </div>

                                    <div>
                                        <dt class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase">
                                            <Users class="size-3" />
                                            Members
                                        </dt>
                                        <dd class="text-foreground mt-1 text-sm">{{ members.length }} in this workspace</dd>
                                    </div>
                                </dl>

                                <Button v-if="canManageProjects" variant="outline" size="sm" class="mt-5 gap-1.5" @click="isEditModalOpen = true">
                                    <Pencil class="size-3.5" />
                                    Edit details
                                </Button>
                            </div>

                            <div v-if="canManageProjects" class="rounded-xl border border-red-200 bg-red-50/40 p-5 dark:border-red-900/40 dark:bg-red-950/10">
                                <div class="mb-1 flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-400">
                                    <Trash2 class="size-4" />
                                    Danger zone
                                </div>
                                <p class="text-muted-foreground mb-4 text-sm">Deleting this project also deletes all of its tasks. This cannot be undone.</p>
                                <Button variant="destructive" size="sm" class="gap-1.5" @click="isDeleteDialogOpen = true">
                                    <Trash2 class="size-3.5" />
                                    Delete project
                                </Button>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </div>
    </AppLayout>

    <EditProjectModal :open="isEditModalOpen" :project="project" @update:open="(value) => (isEditModalOpen = value)" />

    <DeleteProjectDialog :open="isDeleteDialogOpen" :project="project" @update:open="(value) => (isDeleteDialogOpen = value)" @deleted="onDeleted" />

    <CreateTaskModal
        :open="isCreateTaskModalOpen"
        :project-id="project.id"
        :members="members"
        @update:open="(value) => (isCreateTaskModalOpen = value)"
    />

    <EditTaskModal
        :open="taskModalTarget !== null"
        :task="taskModalTarget"
        :members="members"
        :can-manage="canManageTasks"
        @update:open="(value) => !value && (taskModalTarget = null)"
    />

    <DeleteTaskDialog :open="deleteTaskTarget !== null" :task="deleteTaskTarget" @update:open="(value) => !value && (deleteTaskTarget = null)" />
</template>
