<script setup lang="ts">
import { AlertCircle, ArrowRightLeft, CalendarClock, Loader2 } from 'lucide-vue-next';

import { formatDueDate, isOverdue, type BoardColumn, type Task } from '@/lib/tasks';

const props = defineProps<{
    task: Task;
    boardColumns: BoardColumn[];
    canManage: boolean;
    draggable: boolean;
    isDone: boolean;
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
        class="bg-card rounded-lg border p-3 shadow-sm transition-all"
        :class="[
            hasError ? 'border-destructive ring-destructive/30 ring-1' : 'hover:border-foreground/15',
            pending && 'opacity-60',
            draggable && !pending ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
        ]"
        @click="emit('open', task)"
        @dragstart="(event) => emit('drag-start', task, event)"
        @dragend="emit('drag-end')"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm leading-snug font-medium">{{ task.title }}</p>

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

        <div class="mt-3 flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-1.5">
                <AppAvatar v-if="task.assignee_name" :name="task.assignee_name" size="xs" />
                <span class="text-muted-foreground truncate text-[11px]">{{ task.assignee_name ?? 'Unassigned' }}</span>
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <AppBadge v-if="task.due_date" :variant="overdue ? 'danger' : 'neutral'" size="sm">
                    <template #icon>
                        <AlertCircle v-if="overdue" class="size-3" />
                        <CalendarClock v-else class="size-3" />
                    </template>
                    {{ overdue ? 'Overdue · ' : '' }}{{ formatDueDate(task.due_date) }}
                </AppBadge>

                <div v-if="draggable && !pending && otherColumns.length > 0" @click.stop>
                    <AppDropDown :items="moveItems" heading="Move to" align="end" width="w-44" trigger-label="Move task to another column">
                        <template #trigger>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-foreground hover:bg-muted rounded p-1 transition-colors"
                            >
                                <ArrowRightLeft class="size-3.5" />
                            </button>
                        </template>
                    </AppDropDown>
                </div>
            </div>
        </div>
    </div>
</template>
