<script setup lang="ts">
import { AlertCircle, ArrowRightLeft, CalendarClock, CalendarRange, CircleCheck, Loader2 } from 'lucide-vue-next';

import { formatDueDate, isOverdue, type BoardColumn, type Task } from '@/lib/tasks';

const props = defineProps<{
    task: Task;
    boardColumns: BoardColumn[];
    canManage: boolean;
    draggable: boolean;
    isDone: boolean;
    /** Set only when the board is showing every sprint at once. */
    sprintName?: string;
    pending?: boolean;
    hasError?: boolean;
}>();

const emit = defineEmits<{
    (e: 'open', task: Task): void;
    (e: 'edit', task: Task): void;
    (e: 'delete', task: Task): void;
    (e: 'move', task: Task, columnId: number): void;
    (e: 'drag-start', task: Task, event: DragEvent): void;
    (e: 'drag-end'): void;
}>();

const overdue = computed(() => isOverdue(props.task, props.isDone));

const otherColumns = computed(() => props.boardColumns.filter((c) => c.id !== props.task.board_column_id));

const moveItems = computed<DropdownEntry[]>(() =>
    otherColumns.value.map((column) => ({
        label: column.name,
        onSelect: () => emit('move', props.task, column.id),
    })),
);
</script>

<template>
    <div
        :draggable="draggable && !pending"
        class="group bg-card shrink-0 rounded-xl border p-3 shadow-xs transition-all duration-150"
        :class="[
            hasError ? 'border-destructive ring-destructive/30 ring-1' : 'hover:border-foreground/15 hover:-translate-y-px hover:shadow-md',
            pending && 'opacity-60',
            draggable && !pending ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        ]"
        @click="emit('open', task)"
        @dragstart="(event) => emit('drag-start', task, event)"
        @dragend="emit('drag-end')"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm leading-snug font-medium text-pretty" :class="isDone && 'text-muted-foreground'">{{ task.title }}</p>

            <div class="flex shrink-0 items-center gap-1">
                <Loader2 v-if="pending" class="text-muted-foreground size-3.5 animate-spin" />
                <div v-if="canManage" @click.stop>
                    <TaskActionsMenu :task="task" @edit="(t) => emit('edit', t)" @delete="(t) => emit('delete', t)" />
                </div>
            </div>
        </div>

        <p v-if="task.description" class="text-muted-foreground mt-1 line-clamp-2 text-xs leading-relaxed">
            {{ task.description }}
        </p>

        <span
            v-if="sprintName"
            class="border-border text-muted-foreground mt-2 inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium"
        >
            <CalendarRange class="size-2.5" />
            {{ sprintName }}
        </span>

        <div class="border-border/60 mt-3 flex items-center justify-between gap-2 border-t pt-2.5">
            <div class="flex min-w-0 items-center gap-1.5">
                <AppAvatar v-if="task.assignee_name" :name="task.assignee_name" size="xs" />
                <span class="text-muted-foreground truncate text-[11px]">{{ task.assignee_name ?? 'Unassigned' }}</span>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <AppBadge v-if="isDone" variant="success" size="sm">
                    <template #icon>
                        <CircleCheck class="size-3" />
                    </template>
                    Done
                </AppBadge>
                <AppBadge v-else-if="task.due_date" :variant="overdue ? 'danger' : 'neutral'" size="sm">
                    <template #icon>
                        <AlertCircle v-if="overdue" class="size-3" />
                        <CalendarClock v-else class="size-3" />
                    </template>
                    {{ overdue ? 'Overdue · ' : '' }}{{ formatDueDate(task.due_date) }}
                </AppBadge>

                <div v-if="draggable && !pending && otherColumns.length > 0" @click.stop>
                    <AppDropDown :items="moveItems" heading="Move to" align="end" width="w-44" trigger-label="Move task to another list">
                        <template #trigger>
                            <button type="button" class="text-muted-foreground hover:text-foreground hover:bg-muted rounded p-1 transition-colors">
                                <ArrowRightLeft class="size-3.5" />
                            </button>
                        </template>
                    </AppDropDown>
                </div>
            </div>
        </div>
    </div>
</template>
