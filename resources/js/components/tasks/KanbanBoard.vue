<script setup lang="ts">
import { TASK_STATUSES, type Task, type TaskStatusValue } from '@/lib/tasks';

const props = defineProps<{
    tasks: Task[];
    currentUserId: number;
    canManageTasks: boolean;
}>();

const emit = defineEmits<{
    (e: 'open', task: Task): void;
    (e: 'edit', task: Task): void;
    (e: 'delete', task: Task): void;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const notify = useNotificationStore();

/**
 * Independent local copy so a drag can move a card instantly, before the
 * server confirms. Re-synced whenever fresh props arrive (e.g. after any
 * other Inertia visit touching this page).
 */
const localTasks = ref<Task[]>(props.tasks.map((task) => ({ ...task })));

watch(
    () => props.tasks,
    (tasks) => {
        localTasks.value = tasks.map((task) => ({ ...task }));
    },
);

const columns = computed(() =>
    TASK_STATUSES.map((column) => ({
        ...column,
        tasks: localTasks.value.filter((task) => task.status === column.value),
    })),
);

function canDrag(task: Task): boolean {
    return props.canManageTasks || task.assigned_to === props.currentUserId;
}

const draggedTaskId = ref<number | null>(null);
const dragOverColumn = ref<TaskStatusValue | null>(null);
const pendingTaskId = ref<number | null>(null);
const errorTaskId = ref<number | null>(null);

function onDragStart(task: Task, event: DragEvent) {
    draggedTaskId.value = task.id;
    event.dataTransfer?.setData('text/plain', String(task.id));
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDragEnd() {
    draggedTaskId.value = null;
    dragOverColumn.value = null;
}

function onDragOverColumn(status: TaskStatusValue) {
    if (draggedTaskId.value !== null) {
        dragOverColumn.value = status;
    }
}

function onDrop(status: TaskStatusValue) {
    const taskId = draggedTaskId.value;
    draggedTaskId.value = null;
    dragOverColumn.value = null;

    if (taskId === null) return;

    const task = localTasks.value.find((t) => t.id === taskId);
    if (!task || task.status === status || !canDrag(task)) return;

    moveTask(task, status);
}

function moveTask(task: Task, status: TaskStatusValue) {
    const previousStatus = task.status;

    task.status = status;
    pendingTaskId.value = task.id;
    errorTaskId.value = null;

    router.patch(
        workspaceRoute('workspace.projects.tasks.update-status', { project: task.project_id, task: task.id }),
        { status },
        {
            preserveScroll: true,
            onError: () => {
                task.status = previousStatus;
                errorTaskId.value = task.id;
                notify.error(`Couldn't move "${task.title}". Please try again.`);
                setTimeout(() => {
                    if (errorTaskId.value === task.id) errorTaskId.value = null;
                }, 2000);
            },
            onFinish: () => {
                if (pendingTaskId.value === task.id) pendingTaskId.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div v-for="column in columns" :key="column.value" class="flex min-w-0 flex-col gap-3">
            <div class="flex items-center justify-between px-0.5">
                <h3 class="text-foreground text-[13px] font-semibold">{{ column.label }}</h3>
                <span class="text-muted-foreground bg-muted rounded px-1.5 py-0.5 text-[11px] tabular-nums">{{ column.tasks.length }}</span>
            </div>

            <div
                class="flex min-h-32 flex-1 flex-col gap-2.5 rounded-xl border border-dashed p-2.5 transition-colors"
                :class="dragOverColumn === column.value ? 'border-foreground/25 bg-muted/50' : 'border-border/70 bg-muted/10'"
                @dragover.prevent="onDragOverColumn(column.value)"
                @drop.prevent="onDrop(column.value)"
            >
                <TaskCard
                    v-for="task in column.tasks"
                    :key="task.id"
                    :task="task"
                    :can-manage="canManageTasks"
                    :draggable="canDrag(task)"
                    :pending="task.id === pendingTaskId"
                    :has-error="task.id === errorTaskId"
                    @open="(t) => emit('edit', t)"
                    @edit="(t) => emit('edit', t)"
                    @delete="(t) => emit('delete', t)"
                    @drag-start="onDragStart"
                    @drag-end="onDragEnd"
                />

                <p v-if="column.tasks.length === 0" class="text-muted-foreground px-1 py-6 text-center text-xs">
                    {{ dragOverColumn === column.value ? 'Drop here' : 'No tasks here.' }}
                </p>
            </div>
        </div>
    </div>
</template>
