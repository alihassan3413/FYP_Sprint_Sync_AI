<script setup lang="ts">
import type { Sprint } from '@/lib/sprints';
import { CalendarClock, FolderKanban, Loader2, Pencil, User as UserIcon } from 'lucide-vue-next';

import { formatDueDate, isOverdue, type BoardColumn, type Task, type TaskMember } from '@/lib/tasks';

const props = defineProps<{
    sprints?: Sprint[];
    open: boolean;
    task: Task | null;
    members: TaskMember[];
    boardColumns: BoardColumn[];
    projectName: string;
    canManage: boolean;
    currentUserId: number;
    currentUserName: string;
    initialMode: 'view' | 'edit';
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    updated: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const mode = ref<'view' | 'edit'>(props.initialMode);
const threadRef = ref<{ scrollToBottom: () => void } | null>(null);

const form = useForm<{
    title: string;
    description: string;
    assigned_to: number | null;
    due_date: string;
    sprint_id: string;
}>({
    title: '',
    description: '',
    assigned_to: null,
    due_date: '',
    sprint_id: '',
});

watch(
    () => props.task?.id,
    (id, previousId) => {
        if (!props.task || id === previousId) return;

        mode.value = props.canManage ? props.initialMode : 'view';
        form.clearErrors();
        form.title = props.task.title;
        form.description = props.task.description ?? '';
        form.assigned_to = props.task.assigned_to;
        form.due_date = props.task.due_date ?? '';
        form.sprint_id = props.task.sprint_id === null ? '' : String(props.task.sprint_id);
    },
    { immediate: true },
);

const currentColumn = computed(() => props.boardColumns.find((c) => c.id === props.task?.board_column_id) ?? null);
const overdue = computed(() => (props.task ? isOverdue(props.task, currentColumn.value?.is_done ?? false) : false));

function submit() {
    if (!props.task || !props.canManage) return;

    form.put(workspaceRoute('workspace.projects.tasks.update', { project: props.task.project_id, task: props.task.id }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('updated');
            mode.value = 'view';
        },
    });
}

function handleClose(value: boolean) {
    if (form.processing) return;
    emit('update:open', value);
}
</script>

<template>
    <AppModal :open="open" :title="task?.title ?? 'Task'" size="2xl" @update:open="handleClose">
        <template #header>
            <div class="flex items-start justify-between gap-4 pr-8">
                <div class="min-w-0">
                    <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.08em] uppercase">Task</p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <DialogTitle class="text-foreground text-xl leading-tight font-semibold">{{ task?.title }}</DialogTitle>
                        <AppBadge v-if="currentColumn" :variant="currentColumn.is_done ? 'success' : 'neutral'">
                            {{ currentColumn.name }}
                        </AppBadge>
                    </div>
                </div>

                <Button v-if="canManage && mode === 'view'" type="button" variant="outline" size="sm" class="shrink-0 gap-1.5" @click="mode = 'edit'">
                    <Pencil class="size-3.5" />
                    Edit
                </Button>
            </div>
        </template>

        <div v-if="task" class="space-y-8 pt-2">
            <form v-if="mode === 'edit'" id="edit-task-form" class="space-y-5" @submit.prevent="submit">
                <AppFormInput
                    id="edit-task-title"
                    v-model="form.title"
                    label="Title"
                    :error="form.errors.title"
                    required
                    autofocus
                    autocomplete="off"
                />

                <div class="grid gap-1.5">
                    <Label for="edit-task-description" class="text-sm font-medium">
                        Description <span class="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <Textarea id="edit-task-description" v-model="form.description" placeholder="Any context teammates should know?" rows="4" />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-1.5">
                        <Label class="text-sm font-medium">Assignee</Label>
                        <AssigneePicker v-model="form.assigned_to" :members="members" />
                        <InputError :message="form.errors.assigned_to" />
                    </div>

                    <AppFormInput id="edit-task-due-date" v-model="form.due_date" type="date" label="Due date" :error="form.errors.due_date" />
                    <div class="grid gap-1.5">
                        <label :for="'edit-task-sprint'" class="text-foreground text-sm font-medium">Sprint</label>
                        <select
                            :id="'edit-task-sprint'"
                            v-model="form.sprint_id"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                        >
                            <option value="">No sprint</option>
                            <option v-for="sprint in sprints" :key="sprint.id" :value="String(sprint.id)">
                                {{ sprint.name }}{{ sprint.is_current ? ' (current)' : '' }}
                            </option>
                        </select>
                        <InputError :message="form.errors.sprint_id" />
                    </div>
                </div>
            </form>

            <div v-else class="space-y-6">
                <div>
                    <p class="text-muted-foreground text-xs font-semibold tracking-wide uppercase">Description</p>
                    <p class="text-foreground/90 mt-2 text-sm leading-relaxed whitespace-pre-line">
                        {{ task.description || 'No description provided.' }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-6 sm:grid-cols-3">
                    <div>
                        <p class="text-muted-foreground text-[11px] font-semibold tracking-wide uppercase">Assignee</p>
                        <div class="mt-1.5 flex items-center gap-1.5">
                            <AppAvatar v-if="task.assignee_name" :name="task.assignee_name" size="xs" />
                            <UserIcon v-else class="text-muted-foreground size-4" />
                            <span class="text-sm">{{ task.assignee_name ?? 'Unassigned' }}</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-muted-foreground text-[11px] font-semibold tracking-wide uppercase">Due date</p>
                        <div class="mt-1.5 flex items-center gap-1.5 text-sm" :class="overdue && 'text-destructive font-medium'">
                            <CalendarClock class="size-3.5" :class="!overdue && 'text-muted-foreground'" />
                            <span>{{ task.due_date ? formatDueDate(task.due_date) : 'None' }}</span>
                            <span v-if="overdue">· Overdue</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-muted-foreground text-[11px] font-semibold tracking-wide uppercase">Project</p>
                        <div class="mt-1.5 flex items-center gap-1.5 text-sm">
                            <FolderKanban class="text-muted-foreground size-3.5" />
                            <span class="truncate">{{ projectName }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-t pt-6">
                <TaskCommentThread
                    ref="threadRef"
                    :comments="task.comments"
                    :project-id="task.project_id"
                    :task-id="task.id"
                    :current-user-id="currentUserId"
                    :can-moderate="canManage"
                />
            </div>
        </div>

        <template #footer>
            <template v-if="mode === 'edit'">
                <Button type="button" variant="outline" :disabled="form.processing" @click="mode = 'view'"> Cancel </Button>

                <Button type="submit" form="edit-task-form" :disabled="form.processing || form.title.trim().length < 2">
                    <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
            </template>

            <div v-else-if="task" class="w-full">
                <TaskCommentComposer
                    :project-id="task.project_id"
                    :task-id="task.id"
                    :current-user-name="currentUserName"
                    @posted="threadRef?.scrollToBottom()"
                />
            </div>
        </template>
    </AppModal>
</template>
