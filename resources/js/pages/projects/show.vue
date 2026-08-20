<script setup lang="ts">
import { Deferred } from '@inertiajs/vue3';
import { Activity, CalendarClock, FolderKanban, Pencil, Plus, Settings, Sparkles, Trash2, Users } from 'lucide-vue-next';

import ProjectHealthCard from '@/components/analytics/ProjectHealthCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ProjectHealth } from '@/lib/health';
import type { Meeting } from '@/lib/meetings';
import type { Project, ProjectMember } from '@/lib/projects';
import type { Sprint, SprintReport } from '@/lib/sprints';
import type { BoardColumn, Task, TaskMember } from '@/lib/tasks';
import { type BreadcrumbItem, type SharedData } from '@/types';

const props = defineProps<{
    project: Project;
    /** Deferred: the board matters first. */
    health?: ProjectHealth;
    canManageProjects: boolean;
    canDeleteProject: boolean;
    canManageTasks: boolean;
    canManageMeetings: boolean;
    canManageProjectMembers: boolean;
    canManageBoardColumns: boolean;
    canManageSprints: boolean;
    canViewBoard?: boolean;
    activeSprintReport?: SprintReport | null;
    isClient?: boolean;
    clientPermissions?: string[];
    sprints: Sprint[];
    tasks: Task[];
    boardColumns: BoardColumn[];
    meetings: Meeting[];
    members: TaskMember[];
    projectMembers: ProjectMember[];
    workspaceMembers: TaskMember[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const page = usePage<SharedData>();

const currentUserId = computed(() => page.props.auth.user.id);
const currentUserName = computed(() => page.props.auth.user.name);

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

const taskScope = ref<'mine' | 'all'>('mine');

const myTaskCount = computed(() => props.tasks.filter((task) => task.assigned_to === currentUserId.value).length);

const activeSprint = computed(() => props.sprints.find((sprint) => sprint.status === 'active') ?? null);

const sprintNames = computed<Record<number, string>>(() => Object.fromEntries(props.sprints.map((sprint) => [sprint.id, sprint.name])));

/** Default the board to the running sprint — that is the work the team committed to. */
const sprintFilter = ref<number | 'backlog' | 'all'>(activeSprint.value?.id ?? 'all');

watch(activeSprint, (sprint, previous) => {
    if (sprint === null && typeof sprintFilter.value === 'number') {
        sprintFilter.value = 'all';
        return;
    }

    if (sprint !== null && previous === null && sprintFilter.value === 'all') {
        sprintFilter.value = sprint.id;
    }
});

const sprintFilterOptions = computed(() => [
    { value: 'all' as const, label: 'All tasks', count: props.tasks.length },
    ...props.sprints
        .filter((sprint) => sprint.status !== 'completed')
        .map((sprint) => ({
            value: sprint.id,
            label: sprint.status === 'active' ? `${sprint.name} (running)` : sprint.name,
            count: props.tasks.filter((task) => task.sprint_id === sprint.id).length,
        })),
    { value: 'backlog' as const, label: 'Backlog', count: props.tasks.filter((task) => task.sprint_id === null).length },
]);

const taskScopeOptions = computed(() => [
    { value: 'mine', label: 'My tasks', count: myTaskCount.value },
    { value: 'all', label: 'All tasks', count: props.tasks.length },
]);

const showsEmptyPersonalBoard = computed(() => taskScope.value === 'mine' && myTaskCount.value === 0 && props.tasks.length > 0);

const isCreateTaskModalOpen = ref(false);
const taskModalTarget = ref<Task | null>(null);
const taskModalMode = ref<'view' | 'edit'>('view');
const deleteTaskTarget = ref<Task | null>(null);

function openTaskDetails(task: Task) {
    taskModalMode.value = 'view';
    taskModalTarget.value = task;
}

function openTaskEdit(task: Task) {
    taskModalMode.value = 'edit';
    taskModalTarget.value = task;
}

watch(
    () => props.tasks,
    (tasks) => {
        if (taskModalTarget.value === null) return;
        taskModalTarget.value = tasks.find((t) => t.id === taskModalTarget.value!.id) ?? null;
    },
);

const isCreateColumnModalOpen = ref(false);
const deleteColumnTarget = ref<BoardColumn | null>(null);

const isCreateMeetingModalOpen = ref(false);
const meetingModalTarget = ref<Meeting | null>(null);
const deleteMeetingTarget = ref<Meeting | null>(null);

const isAddMemberModalOpen = ref(false);
const roleModalTarget = ref<ProjectMember | null>(null);
const removeMemberTarget = ref<ProjectMember | null>(null);

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('task');
    const meetingId = params.get('meeting');

    if (taskId !== null) {
        const task = props.tasks.find((t) => t.id === Number(taskId));
        if (task) openTaskDetails(task);
        return;
    }

    if (meetingId !== null) {
        const meeting = props.meetings.find((m) => m.id === Number(meetingId));
        if (meeting) meetingModalTarget.value = meeting;
    }
});

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
                        <TabsTrigger value="sprints">Sprints</TabsTrigger>
                        <TabsTrigger value="meetings">Meetings</TabsTrigger>
                        <TabsTrigger value="activity">Activity</TabsTrigger>
                        <TabsTrigger value="settings">Settings</TabsTrigger>
                    </TabsList>

                    <!-- Board -->
                    <TabsContent value="board">
                        <div class="mb-4 flex min-h-9 items-center justify-between gap-3">
                            <div class="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto">
                                <AppSegmentedControl v-if="tasks.length > 0" v-model="taskScope" :options="taskScopeOptions" />
                                <p v-else class="text-muted-foreground text-xs">No tasks yet</p>

                                <select
                                    v-if="sprints.length > 0 && tasks.length > 0"
                                    v-model="sprintFilter"
                                    aria-label="Sprint"
                                    class="border-input bg-background h-8 shrink-0 rounded-lg border px-2 text-xs"
                                >
                                    <option v-for="option in sprintFilterOptions" :key="String(option.value)" :value="option.value">
                                        {{ option.label }} ({{ option.count }})
                                    </option>
                                </select>
                            </div>

                            <Button v-if="canManageTasks" size="sm" class="shrink-0 gap-1.5" @click="isCreateTaskModalOpen = true">
                                <Plus class="size-3.5" />
                                New task
                            </Button>
                        </div>

                        <div
                            v-if="showsEmptyPersonalBoard"
                            class="border-border/70 bg-muted/20 mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed px-4 py-3"
                        >
                            <p class="text-muted-foreground text-xs">
                                Nothing on this board is assigned to you yet.
                                <span class="text-foreground font-medium">{{ tasks.length }}</span>
                                {{ tasks.length === 1 ? 'task belongs' : 'tasks belong' }} to the team.
                            </p>

                            <Button variant="outline" size="sm" class="h-7 text-xs" @click="taskScope = 'all'"> View all tasks </Button>
                        </div>

                        <KanbanBoard
                            v-if="tasks.length > 0 || canManageTasks"
                            :project-id="project.id"
                            :tasks="tasks"
                            :board-columns="boardColumns"
                            :current-user-id="currentUserId"
                            :can-manage-tasks="canManageTasks"
                            :can-manage-board-columns="canManageBoardColumns"
                            :scope="taskScope"
                            :sprint-filter="sprintFilter"
                            :sprint-names="sprintNames"
                            @open="openTaskDetails"
                            @edit="openTaskEdit"
                            @delete="(task) => (deleteTaskTarget = task)"
                            @create-column="isCreateColumnModalOpen = true"
                            @delete-column="(column) => (deleteColumnTarget = column)"
                        />

                        <AppEmptyState
                            v-else-if="canViewBoard === false"
                            title="The board is not shared with you"
                            description="Your client access to this project does not include the task board. Ask the team if you need it."
                        >
                            <template #icon>
                                <FolderKanban class="size-5" />
                            </template>
                        </AppEmptyState>

                        <AppEmptyState v-else title="No tasks yet" description="Nobody has added a task to this project yet.">
                            <template #icon>
                                <FolderKanban class="size-5" />
                            </template>
                        </AppEmptyState>
                    </TabsContent>

                    <!-- Meetings -->
                    <TabsContent value="sprints">
                        <!-- How the project is really going, above the sprint detail -->
                        <Deferred data="health">
                            <template #fallback>
                                <div class="bg-card mb-4 animate-pulse rounded-xl border p-6">
                                    <div class="bg-muted h-4 w-44 rounded"></div>
                                    <div class="bg-muted mt-4 h-1.5 w-full rounded-full"></div>
                                    <div class="bg-muted mt-5 h-16 w-full rounded-lg"></div>
                                </div>
                            </template>

                            <div v-if="health" class="mb-4">
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="grid size-6 place-items-center rounded-full bg-lime-400/20">
                                        <Sparkles class="size-3.5 text-lime-600 dark:text-lime-400" />
                                    </span>
                                    <h3 class="text-[15px] font-semibold tracking-tight">Project health</h3>
                                    <AppBadge variant="purple" size="sm">AI</AppBadge>
                                </div>
                                <ProjectHealthCard :health="health" hide-title />
                            </div>
                        </Deferred>

                        <SprintPanel
                            :project-id="project.id"
                            :sprints="sprints"
                            :can-manage="canManageSprints"
                            :active-sprint-report="activeSprintReport"
                        />
                    </TabsContent>

                    <TabsContent value="meetings">
                        <MeetingsList
                            :meetings="meetings"
                            :can-manage="canManageMeetings"
                            @create="isCreateMeetingModalOpen = true"
                            @open="(meeting) => (meetingModalTarget = meeting)"
                            @edit="(meeting) => (meetingModalTarget = meeting)"
                            @delete="(meeting) => (deleteMeetingTarget = meeting)"
                        />
                    </TabsContent>

                    <!-- Activity (placeholder) -->
                    <TabsContent value="activity">
                        <AppEmptyState
                            title="Activity is coming soon"
                            description="A timeline of everything that happens in this project will show up here."
                        >
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
                                        <dd class="text-foreground mt-1 text-sm leading-relaxed">
                                            {{ project.description || 'No description yet.' }}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt
                                            class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase"
                                        >
                                            <CalendarClock class="size-3" />
                                            Created
                                        </dt>
                                        <dd class="text-foreground mt-1 text-sm">{{ formatDate(project.created_at) }}</dd>
                                    </div>
                                </dl>

                                <Button v-if="canManageProjects" variant="outline" size="sm" class="mt-5 gap-1.5" @click="isEditModalOpen = true">
                                    <Pencil class="size-3.5" />
                                    Edit details
                                </Button>
                            </div>

                            <div class="bg-card rounded-xl border p-5 shadow-sm">
                                <div class="mb-4 flex items-center justify-between">
                                    <div class="flex items-center gap-2 text-sm font-medium">
                                        <Users class="text-muted-foreground size-4" />
                                        Project members
                                    </div>

                                    <Button
                                        v-if="canManageProjectMembers"
                                        size="sm"
                                        variant="outline"
                                        class="gap-1.5"
                                        @click="isAddMemberModalOpen = true"
                                    >
                                        <Plus class="size-3.5" />
                                        Add member
                                    </Button>
                                </div>

                                <div v-if="projectMembers.length > 0" class="divide-y">
                                    <div v-for="pm in projectMembers" :key="pm.id" class="flex items-center justify-between py-2.5">
                                        <div class="flex items-center gap-3">
                                            <AppAvatar :name="pm.name" size="sm" />
                                            <div>
                                                <p class="text-sm font-medium">{{ pm.name }}</p>
                                                <p class="text-muted-foreground text-xs">{{ pm.email }}</p>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <AppRoleBadge :role="pm.role" />
                                            <ProjectMemberActionsMenu
                                                v-if="canManageProjectMembers"
                                                :member="pm"
                                                @change-role="(m) => (roleModalTarget = m)"
                                                @remove="(m) => (removeMemberTarget = m)"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <AppEmptyState
                                    v-else
                                    title="No members assigned"
                                    description="Workspace owners and admins can always access this project. Assign members to give others access."
                                >
                                    <template #icon>
                                        <Users class="size-5" />
                                    </template>
                                </AppEmptyState>
                            </div>

                            <div
                                v-if="canDeleteProject"
                                class="rounded-xl border border-red-200 bg-red-50/40 p-5 dark:border-red-900/40 dark:bg-red-950/10"
                            >
                                <div class="mb-1 flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-400">
                                    <Trash2 class="size-4" />
                                    Danger zone
                                </div>
                                <p class="text-muted-foreground mb-4 text-sm">
                                    Deleting this project also deletes all of its tasks. This cannot be undone.
                                </p>
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

    <EditProjectModal v-if="canManageProjects" :open="isEditModalOpen" :project="project" @update:open="(value) => (isEditModalOpen = value)" />

    <DeleteProjectDialog
        v-if="canDeleteProject"
        :open="isDeleteDialogOpen"
        :project="project"
        @update:open="(value) => (isDeleteDialogOpen = value)"
        @deleted="onDeleted"
    />

    <CreateTaskModal
        v-if="canManageTasks"
        :open="isCreateTaskModalOpen"
        :project-id="project.id"
        :members="members"
        :sprints="sprints"
        @update:open="(value) => (isCreateTaskModalOpen = value)"
    />

    <TaskDetailModal
        :open="taskModalTarget !== null"
        :task="taskModalTarget"
        :members="members"
        :sprints="sprints"
        :board-columns="boardColumns"
        :project-name="project.name"
        :can-manage="canManageTasks"
        :current-user-id="currentUserId"
        :current-user-name="currentUserName"
        :initial-mode="taskModalMode"
        @update:open="(value) => !value && (taskModalTarget = null)"
    />

    <DeleteTaskDialog
        v-if="canManageTasks"
        :open="deleteTaskTarget !== null"
        :task="deleteTaskTarget"
        @update:open="(value) => !value && (deleteTaskTarget = null)"
    />

    <CreateBoardColumnModal
        v-if="canManageBoardColumns"
        :open="isCreateColumnModalOpen"
        :project-id="project.id"
        @update:open="(value) => (isCreateColumnModalOpen = value)"
    />

    <DeleteBoardColumnDialog
        v-if="canManageBoardColumns"
        :open="deleteColumnTarget !== null"
        :column="deleteColumnTarget"
        :project-id="project.id"
        @update:open="(value) => !value && (deleteColumnTarget = null)"
    />

    <CreateMeetingModal
        v-if="canManageMeetings"
        :open="isCreateMeetingModalOpen"
        :project-id="project.id"
        :participant-options="projectMembers"
        @update:open="(value) => (isCreateMeetingModalOpen = value)"
    />

    <EditMeetingModal
        :open="meetingModalTarget !== null"
        :meeting="meetingModalTarget"
        :can-manage="canManageMeetings"
        :participant-options="projectMembers"
        @update:open="(value) => !value && (meetingModalTarget = null)"
    />

    <DeleteMeetingDialog
        v-if="canManageMeetings"
        :open="deleteMeetingTarget !== null"
        :meeting="deleteMeetingTarget"
        @update:open="(value) => !value && (deleteMeetingTarget = null)"
    />

    <template v-if="canManageProjectMembers">
        <AddProjectMemberModal
            :open="isAddMemberModalOpen"
            :project-id="project.id"
            :workspace-members="workspaceMembers"
            :project-members="projectMembers"
            @update:open="(value) => (isAddMemberModalOpen = value)"
        />

        <ChangeProjectMemberRoleModal
            :open="roleModalTarget !== null"
            :project-id="project.id"
            :member="roleModalTarget"
            @update:open="(value) => !value && (roleModalTarget = null)"
        />

        <RemoveProjectMemberDialog
            :open="removeMemberTarget !== null"
            :project-id="project.id"
            :member="removeMemberTarget"
            @update:open="(value) => !value && (removeMemberTarget = null)"
        />
    </template>
</template>
