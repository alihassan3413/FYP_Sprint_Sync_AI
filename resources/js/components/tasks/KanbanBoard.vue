<script setup lang="ts">
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';

import type { BoardColumn, Task } from '@/lib/tasks';

const props = withDefaults(
    defineProps<{
        tasks: Task[];
        boardColumns: BoardColumn[];
        currentUserId: number;
        canManageTasks: boolean;
        canManageBoardColumns: boolean;
        scope?: 'mine' | 'all';
    }>(),
    {
        scope: 'all',
    },
);

const emit = defineEmits<{
    (e: 'open', task: Task): void;
    (e: 'edit', task: Task): void;
    (e: 'delete', task: Task): void;
    (e: 'create-column'): void;
    (e: 'delete-column', column: BoardColumn): void;
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

/**
 * Local column order, independent from `props.boardColumns`, so dragging a
 * column header reorders instantly before the server confirms.
 */
const localColumnOrder = ref<BoardColumn[]>([...props.boardColumns].sort((a, b) => a.position - b.position));

watch(
    () => props.boardColumns,
    (boardColumns) => {
        localColumnOrder.value = [...boardColumns].sort((a, b) => a.position - b.position);
    },
);

const visibleTasks = computed(() =>
    props.scope === 'mine' ? localTasks.value.filter((task) => task.assigned_to === props.currentUserId) : localTasks.value,
);

const columns = computed(() =>
    localColumnOrder.value.map((column) => ({
        ...column,
        tasks: visibleTasks.value.filter((task) => task.board_column_id === column.id),
    })),
);

function canDrag(task: Task): boolean {
    return props.canManageTasks || task.assigned_to === props.currentUserId;
}

const draggedTaskId = ref<number | null>(null);
const dragOverColumnId = ref<number | null>(null);
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
    dragOverColumnId.value = null;
}

function onDragOverColumn(columnId: number) {
    if (draggedTaskId.value !== null) {
        dragOverColumnId.value = columnId;
    }
}

function onDrop(columnId: number) {
    const taskId = draggedTaskId.value;
    draggedTaskId.value = null;
    dragOverColumnId.value = null;

    if (taskId === null) return;

    const task = localTasks.value.find((t) => t.id === taskId);
    if (!task || task.board_column_id === columnId || !canDrag(task)) return;

    moveTask(task, columnId);
}

function moveTask(task: Task, columnId: number) {
    const previousColumnId = task.board_column_id;

    task.board_column_id = columnId;
    pendingTaskId.value = task.id;
    errorTaskId.value = null;

    router.patch(
        workspaceRoute('workspace.projects.tasks.update-status', { project: task.project_id, task: task.id }),
        { board_column_id: columnId },
        {
            preserveScroll: true,
            onError: () => {
                task.board_column_id = previousColumnId;
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

const draggedColumnId = ref<number | null>(null);
const dragOverColumnHeaderId = ref<number | null>(null);
const columnsPending = ref(false);

function onColumnDragStart(column: BoardColumn, event: DragEvent) {
    if (!props.canManageBoardColumns) return;

    draggedColumnId.value = column.id;
    event.dataTransfer?.setData('text/plain', String(column.id));
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onColumnDragEnd() {
    draggedColumnId.value = null;
    dragOverColumnHeaderId.value = null;
}

function onColumnDragOver(column: BoardColumn) {
    if (draggedColumnId.value !== null && draggedColumnId.value !== column.id) {
        dragOverColumnHeaderId.value = column.id;
    }
}

function onColumnDrop(targetColumn: BoardColumn) {
    const draggedId = draggedColumnId.value;
    draggedColumnId.value = null;
    dragOverColumnHeaderId.value = null;

    if (draggedId === null || draggedId === targetColumn.id) return;

    const fromIndex = localColumnOrder.value.findIndex((c) => c.id === draggedId);
    const toIndex = localColumnOrder.value.findIndex((c) => c.id === targetColumn.id);
    if (fromIndex === -1 || toIndex === -1) return;

    reorderColumns(fromIndex, toIndex);
}

function reorderColumns(fromIndex: number, toIndex: number) {
    const previousOrder = [...localColumnOrder.value];

    const reordered = [...localColumnOrder.value];
    const [moved] = reordered.splice(fromIndex, 1);
    reordered.splice(toIndex, 0, moved);
    localColumnOrder.value = reordered;

    columnsPending.value = true;

    router.patch(
        workspaceRoute('workspace.projects.board-columns.reorder', { project: moved.project_id }),
        { column_ids: reordered.map((c) => c.id) },
        {
            preserveScroll: true,
            onError: () => {
                localColumnOrder.value = previousOrder;
                notify.error("Couldn't reorder columns. Please try again.");
            },
            onFinish: () => {
                columnsPending.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="flex items-stretch gap-4 overflow-x-auto pb-2">
        <div v-for="column in columns" :key="column.id" class="flex w-72 shrink-0 flex-col gap-3">
            <div
                class="flex items-center justify-between gap-1 rounded-md px-0.5 transition-colors"
                :class="[canManageBoardColumns && 'cursor-grab active:cursor-grabbing', dragOverColumnHeaderId === column.id && 'bg-muted/60']"
                :draggable="canManageBoardColumns && !columnsPending"
                @dragstart="(event) => onColumnDragStart(column, event)"
                @dragend="onColumnDragEnd"
                @dragover.prevent="onColumnDragOver(column)"
                @drop.prevent="onColumnDrop(column)"
            >
                <div class="flex min-w-0 items-center gap-1">
                    <GripVertical v-if="canManageBoardColumns" class="text-muted-foreground/50 size-3.5 shrink-0" />
                    <h3 class="text-foreground truncate text-[13px] font-semibold">{{ column.name }}</h3>
                    <span class="text-muted-foreground bg-muted rounded px-1.5 py-0.5 text-[11px] tabular-nums">{{ column.tasks.length }}</span>
                </div>

                <button
                    v-if="canManageBoardColumns && !column.is_default"
                    type="button"
                    class="text-muted-foreground hover:text-destructive shrink-0 rounded p-1 transition-colors"
                    aria-label="Delete column"
                    @click.stop="emit('delete-column', column)"
                >
                    <Trash2 class="size-3.5" />
                </button>
            </div>

            <div
                class="flex min-h-32 flex-1 flex-col gap-2.5 rounded-xl border border-dashed p-2.5 transition-colors"
                :class="dragOverColumnId === column.id ? 'border-foreground/25 bg-muted/50' : 'border-border/70 bg-muted/10'"
                @dragover.prevent="onDragOverColumn(column.id)"
                @drop.prevent="onDrop(column.id)"
            >
                <TaskCard
                    v-for="task in column.tasks"
                    :key="task.id"
                    :task="task"
                    :board-columns="localColumnOrder"
                    :can-manage="canManageTasks"
                    :draggable="canDrag(task)"
                    :is-done="column.is_done"
                    :pending="task.id === pendingTaskId"
                    :has-error="task.id === errorTaskId"
                    @open="(t) => emit('open', t)"
                    @edit="(t) => emit('edit', t)"
                    @delete="(t) => emit('delete', t)"
                    @move="(t, columnId) => moveTask(t, columnId)"
                    @drag-start="onDragStart"
                    @drag-end="onDragEnd"
                />

                <p v-if="column.tasks.length === 0" class="text-muted-foreground px-1 py-6 text-center text-xs">
                    <template v-if="dragOverColumnId === column.id">Drop here</template>
                    <template v-else-if="scope === 'mine'">Nothing assigned to you.</template>
                    <template v-else>No tasks here.</template>
                </p>
            </div>
        </div>

        <button
            v-if="canManageBoardColumns"
            type="button"
            class="text-muted-foreground hover:border-foreground/25 hover:text-foreground hover:bg-muted/40 flex w-72 shrink-0 items-center justify-center gap-1.5 self-start rounded-xl border border-dashed p-3 text-xs font-medium transition-colors"
            @click="emit('create-column')"
        >
            <Plus class="size-3.5" />
            Add column
        </button>
    </div>
</template>
