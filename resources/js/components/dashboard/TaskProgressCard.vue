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
        <div class="mb-5 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <ListChecks class="text-muted-foreground size-4" />
                <h3 class="text-[15px] font-semibold tracking-tight">Task completion</h3>
            </div>

            <span class="text-muted-foreground text-xs tabular-nums">{{ progress.completed }} of {{ progress.total }} done</span>
        </div>

        <!--
            Two columns on purpose. As a single stacked column this card was a
            narrow ribbon of content in a very wide box, which read as padding
            rather than design.
        -->
        <div v-if="progress.total > 0" class="grid gap-6 sm:grid-cols-[minmax(0,15rem)_1fr] sm:gap-8">
            <div class="sm:border-border sm:border-r sm:pr-8">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-[40px] leading-none font-semibold tracking-[-0.03em] tabular-nums">
                        {{ progress.completion_percentage }}<span class="text-[0.5em] font-medium">%</span>
                    </span>
                </div>
                <p class="text-muted-foreground mt-1 text-[11.5px]">complete</p>

                <div class="bg-muted mt-4 h-1.5 w-full overflow-hidden rounded-full">
                    <div
                        class="h-full rounded-full bg-[#a3e635] transition-all duration-700"
                        :style="{ width: `${progress.completion_percentage}%` }"
                    />
                </div>

                <dl class="mt-5 space-y-2">
                    <div class="flex items-center justify-between text-[12.5px]">
                        <dt class="text-muted-foreground">Open</dt>
                        <dd class="font-medium tabular-nums">{{ progress.open }}</dd>
                    </div>
                    <div class="flex items-center justify-between text-[12.5px]">
                        <dt class="text-muted-foreground">Overdue</dt>
                        <dd class="font-medium tabular-nums" :class="progress.overdue > 0 ? 'text-rose-500' : ''">
                            {{ progress.overdue }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="bars.length > 0" class="min-w-0">
                <p class="text-muted-foreground mb-3 text-[10.5px] font-semibold tracking-[0.12em] uppercase">By list</p>
                <AppBarList :items="bars" />
            </div>
        </div>

        <p v-else class="text-muted-foreground py-6 text-center text-xs">
            No tasks yet. Create a project and add your first task to see progress here.
        </p>
    </div>
</template>
