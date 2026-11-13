<script setup lang="ts">
import { CircleCheck, CircleDashed, GripVertical, Inbox, Plus, Trash2 } from 'lucide-vue-next';

import type { BoardColumn, Task } from '@/lib/tasks';

const props = withDefaults(
    defineProps<{
        projectId: number;
        tasks: Task[];
        boardColumns: BoardColumn[];
        currentUserId: number;
        canManageTasks: boolean;
        canManageBoardColumns: boolean;
        scope?: 'mine' | 'all';
        /** Sprint focus: a sprint id, 'backlog' for unplanned work, or 'all'. */
        sprintFilter?: number | 'backlog' | 'all';
        /** Sprint id → name, so a card can show which sprint it belongs to. */
        sprintNames?: Record<number, string>;
    }>(),
    {
        scope: 'all',
        sprintFilter: 'all',
        sprintNames: () => ({}),
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

useProjectTaskStream(props.projectId, (payload) => {
    if (payload.project_id !== props.projectId) return;

    const task = localTasks.value.find((candidate) => candidate.id === payload.task_id);
    if (!task) return;

    if (!localColumnOrder.value.some((column) => column.id === payload.board_column_id)) {
        router.reload({ only: ['tasks', 'boardColumns'] });
        return;
    }

    if (task.board_column_id === payload.board_column_id) return;

    task.board_column_id = payload.board_column_id;
});

const visibleTasks = computed(() => {
    let tasks = localTasks.value;

    if (props.scope === 'mine') {
        tasks = tasks.filter((task) => task.assigned_to === props.currentUserId);
    }

    if (props.sprintFilter === 'backlog') {
        tasks = tasks.filter((task) => task.sprint_id === null);
    } else if (typeof props.sprintFilter === 'number') {
        tasks = tasks.filter((task) => task.sprint_id === props.sprintFilter);
    }

    return tasks;
});

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

/** Done lists are green, the first list grey, every other stage blue. */
function columnDot(column: BoardColumn): string {
    if (column.is_done) return 'bg-emerald-500';
    return localColumnOrder.value[0]?.id === column.id ? 'bg-slate-400' : 'bg-sky-500';
}

function setColumnDone(column: BoardColumn, isDone: boolean) {
    router.patch(
        workspaceRoute('workspace.projects.board-columns.update', { project: column.project_id, boardColumn: column.id }),
        { is_done: isDone },
        {
            preserveScroll: true,
            onError: () => notify.error(`Couldn't update "${column.name}". Please try again.`),
        },
    );
}

function columnActions(column: BoardColumn): DropdownEntry[] {
    return [
        column.is_done
            ? { label: 'Stop counting as done', icon: CircleDashed, onSelect: () => setColumnDone(column, false) }
            : { label: 'Mark as done list', icon: CircleCheck, onSelect: () => setColumnDone(column, true) },
        null,
        { label: 'Delete list', icon: Trash2, destructive: true, onSelect: () => emit('delete-column', column) },
    ];
}
</script>

<template>
    <div class="flex items-start gap-3 overflow-x-auto pb-3 [scrollbar-width:thin]">
        <section
            v-for="column in columns"
            :key="column.id"
            class="bg-muted/40 dark:bg-muted/20 flex max-h-[calc(100dvh-15rem)] min-h-56 w-[19rem] shrink-0 flex-col rounded-2xl border transition-colors"
            :class="[
                dragOverColumnId === column.id && 'border-primary/40 bg-primary/5 ring-primary/15 ring-2',
                dragOverColumnHeaderId === column.id && 'ring-foreground/15 ring-2',
            ]"
            :aria-label="`${column.name} list`"
            @dragover.prevent="onDragOverColumn(column.id)"
            @drop.prevent="onDrop(column.id)"
        >
            <header
                class="flex items-center justify-between gap-2 px-3 pt-3 pb-2"
                :class="canManageBoardColumns && 'cursor-grab active:cursor-grabbing'"
                :draggable="canManageBoardColumns && !columnsPending"
                @dragstart="(event) => onColumnDragStart(column, event)"
                @dragend="onColumnDragEnd"
                @dragover.prevent="onColumnDragOver(column)"
                @drop.prevent="onColumnDrop(column)"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <GripVertical v-if="canManageBoardColumns" class="text-muted-foreground/40 -mr-1 size-3.5 shrink-0" />
                    <span class="size-2 shrink-0 rounded-full" :class="columnDot(column)" aria-hidden="true" />
                    <h3 class="text-foreground truncate text-[13px] font-semibold">{{ column.name }}</h3>
                    <span class="bg-background text-muted-foreground rounded-full border px-1.5 text-[11px] leading-5 font-medium tabular-nums">
                        {{ column.tasks.length }}
                    </span>
                    <span
                        v-if="column.is_done && !column.is_default"
                        class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400"
                        title="Tasks in this list count as done"
                    >
                        <CircleCheck class="size-3" />
                        Done
                    </span>
                </div>

                <div v-if="canManageBoardColumns && !column.is_default" draggable="false" @click.stop @dragstart.stop.prevent>
                    <AppDropDown :items="columnActions(column)" :heading="column.name" align="end" width="w-56" trigger-label="List actions" />
                </div>
            </header>

            <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2 [scrollbar-width:thin]">
                <TaskCard
                    v-for="task in column.tasks"
                    :key="task.id"
                    :task="task"
                    :board-columns="localColumnOrder"
                    :can-manage="canManageTasks"
                    :draggable="canDrag(task)"
                    :is-done="column.is_done"
                    :sprint-name="sprintFilter === 'all' && task.sprint_id !== null ? sprintNames[task.sprint_id] : undefined"
                    :pending="task.id === pendingTaskId"
                    :has-error="task.id === errorTaskId"
                    @open="(t) => emit('open', t)"
                    @edit="(t) => emit('edit', t)"
                    @delete="(t) => emit('delete', t)"
                    @move="(t, columnId) => moveTask(t, columnId)"
                    @drag-start="onDragStart"
                    @drag-end="onDragEnd"
                />

                <div
                    v-if="column.tasks.length === 0"
                    class="text-muted-foreground border-border/80 flex flex-1 flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed px-3 py-8 text-center text-xs"
                >
                    <Inbox class="size-4 opacity-50" />
                    <template v-if="dragOverColumnId === column.id">Drop here</template>
                    <template v-else-if="scope === 'mine'">Nothing assigned to you.</template>
                    <template v-else-if="sprintFilter !== 'all'">Nothing here in this sprint.</template>
                    <template v-else>No tasks here.</template>
                </div>
            </div>
        </section>

        <button
            v-if="canManageBoardColumns"
            type="button"
            class="text-muted-foreground hover:border-foreground/25 hover:text-foreground hover:bg-muted/40 flex h-12 w-[19rem] shrink-0 items-center justify-center gap-1.5 rounded-2xl border border-dashed text-xs font-medium transition-colors"
            @click="emit('create-column')"
        >
            <Plus class="size-3.5" />
            Add list
        </button>
    </div>
</template>
