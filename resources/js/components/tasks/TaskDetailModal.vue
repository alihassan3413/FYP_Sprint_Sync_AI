<script setup lang="ts">
import { CalendarClock, Loader2, Pencil, User as UserIcon } from 'lucide-vue-next';

import { formatDueDate, isOverdue, type BoardColumn, type Task, type TaskMember } from '@/lib/tasks';

const props = defineProps<{
    open: boolean;
    task: Task | null;
    members: TaskMember[];
    boardColumns: BoardColumn[];
    canManage: boolean;
    initialMode: 'view' | 'edit';
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    updated: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const mode = ref<'view' | 'edit'>(props.initialMode);

const form = useForm<{
    title: string;
    description: string;
    assigned_to: number | null;
    due_date: string;
}>({
    title: '',
    description: '',
    assigned_to: null,
    due_date: '',
});

watch(
    () => props.task,
    (task) => {
        if (!task) return;

        mode.value = props.canManage ? props.initialMode : 'view';
        form.clearErrors();
        form.title = task.title;
        form.description = task.description ?? '';
        form.assigned_to = task.assigned_to;
        form.due_date = task.due_date ?? '';
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
    <AppModal :open="open" :title="mode === 'edit' ? 'Edit task' : 'Task details'" size="md" @update:open="handleClose">
        <form v-if="task && mode === 'edit'" id="edit-task-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput id="edit-task-title" v-model="form.title" label="Title" :error="form.errors.title" required autofocus autocomplete="off" />

            <div class="grid gap-1.5">
                <Label for="edit-task-description" class="text-sm font-medium">
                    Description <span class="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <Textarea id="edit-task-description" v-model="form.description" placeholder="Any context teammates should know?" rows="3" />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-1.5">
                    <Label class="text-sm font-medium">Assignee</Label>
                    <AssigneePicker v-model="form.assigned_to" :members="members" />
                    <InputError :message="form.errors.assigned_to" />
                </div>

                <AppFormInput id="edit-task-due-date" v-model="form.due_date" type="date" label="Due date" :error="form.errors.due_date" />
            </div>
        </form>

        <div v-else-if="task" class="space-y-4 pt-2">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-foreground text-sm font-medium">{{ task.title }}</p>
                <AppBadge v-if="currentColumn" :variant="currentColumn.is_done ? 'success' : 'neutral'" size="sm">
                    {{ currentColumn.name }}
                </AppBadge>
            </div>

            <div>
                <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Description</p>
                <p class="text-foreground mt-1 text-sm leading-relaxed">{{ task.description || 'No description.' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Assignee</p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <AppAvatar v-if="task.assignee_name" :name="task.assignee_name" size="xs" />
                        <UserIcon v-else class="text-muted-foreground size-4" />
                        <span class="text-sm">{{ task.assignee_name ?? 'Unassigned' }}</span>
                    </div>
                </div>

                <div>
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Due date</p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <CalendarClock class="text-muted-foreground size-3.5" />
                        <span class="text-sm" :class="overdue && 'text-destructive font-medium'">
                            {{ task.due_date ? formatDueDate(task.due_date) : 'None' }}
                            <span v-if="overdue"> · Overdue</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <template v-if="mode === 'view'">
                <Button type="button" variant="outline" @click="handleClose(false)"> Close </Button>
                <Button v-if="canManage" type="button" class="gap-1.5" @click="mode = 'edit'">
                    <Pencil class="size-3.5" />
                    Edit
                </Button>
            </template>

            <template v-else>
                <Button type="button" variant="outline" :disabled="form.processing" @click="mode = 'view'"> Cancel </Button>

                <Button type="submit" form="edit-task-form" :disabled="form.processing || form.title.trim().length < 2">
                    <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
            </template>
        </template>
    </AppModal>
</template>
