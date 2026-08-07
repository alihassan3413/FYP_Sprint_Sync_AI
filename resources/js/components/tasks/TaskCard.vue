<script setup lang="ts">
import { AlertCircle, CalendarClock, Loader2 } from 'lucide-vue-next';

import { formatDueDate, isOverdue, type Task } from '@/lib/tasks';

const props = defineProps<{
    task: Task;
    canManage: boolean;
    draggable: boolean;
    pending?: boolean;
    hasError?: boolean;
}>();

const emit = defineEmits<{
    (e: 'open', task: Task): void;
    (e: 'edit', task: Task): void;
    (e: 'delete', task: Task): void;
    (e: 'drag-start', task: Task, event: DragEvent): void;
    (e: 'drag-end'): void;
}>();

const overdue = computed(() => isOverdue(props.task));
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

            <AppBadge v-if="task.due_date" :variant="overdue ? 'danger' : 'neutral'" size="sm">
                <template #icon>
                    <AlertCircle v-if="overdue" class="size-3" />
                    <CalendarClock v-else class="size-3" />
                </template>
                {{ overdue ? 'Overdue · ' : '' }}{{ formatDueDate(task.due_date) }}
            </AppBadge>
        </div>
    </div>
</template>
