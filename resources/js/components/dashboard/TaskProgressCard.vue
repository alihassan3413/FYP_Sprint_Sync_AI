<script setup lang="ts">
import { ListChecks } from 'lucide-vue-next';

export interface TaskColumnBreakdown {
    name: string;
    is_done: boolean;
    count: number;
}

export interface TaskProgress {
    total: number;
    completed: number;
    open: number;
    overdue: number;
    completion_percentage: number;
    columns: TaskColumnBreakdown[];
}

const props = defineProps<{
    progress: TaskProgress;
}>();

const bars = computed(() =>
    props.progress.columns.map((column) => ({
        label: column.name,
        count: column.count,
        tone: column.is_done ? ('success' as const) : ('default' as const),
    })),
);
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm sm:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <ListChecks class="text-muted-foreground size-4" />
                <h3 class="text-[15px] font-semibold tracking-tight">Task completion</h3>
            </div>

            <span class="text-muted-foreground text-xs tabular-nums"> {{ progress.completed }} of {{ progress.total }} done </span>
        </div>

        <template v-if="progress.total > 0">
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-semibold tracking-tight tabular-nums">{{ progress.completion_percentage }}%</span>
                <span class="text-muted-foreground text-xs">complete</span>
            </div>

            <div class="bg-muted mt-3 h-2 w-full overflow-hidden rounded-full">
                <div
                    class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                    :style="{ width: `${progress.completion_percentage}%` }"
                />
            </div>

            <div class="text-muted-foreground mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs tabular-nums">
                <span>{{ progress.open }} open</span>
                <span v-if="progress.overdue > 0" class="text-destructive font-medium">{{ progress.overdue }} overdue</span>
            </div>

            <div v-if="bars.length > 0" class="mt-5">
                <p class="text-muted-foreground mb-3 text-[11px] font-medium tracking-[0.06em] uppercase">By column</p>
                <AppBarList :items="bars" />
            </div>
        </template>

        <p v-else class="text-muted-foreground py-6 text-center text-xs">
            No tasks yet. Create a project and add your first task to see progress here.
        </p>
    </div>
</template>
