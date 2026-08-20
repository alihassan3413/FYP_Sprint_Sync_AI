<script setup lang="ts">
import { ArrowUpRight } from 'lucide-vue-next';

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
    /** Where the "open it" affordance points. */
    href?: string;
}>();

/**
 * Segment colours, in board order, drawn from the product palette: lime for
 * finished work, indigo and violet for what is still moving, and a neutral for
 * anything beyond that. Done always takes lime regardless of position, because
 * the eye should find "finished" in the same colour everywhere in the app.
 */
const PALETTE = ['#365AFF', '#A78BFA', '#38BDF8', '#F59E0B'];
const DONE = '#A3E635';

const RADIUS = 54;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const segments = computed(() => {
    const total = props.progress.columns.reduce((sum, column) => sum + column.count, 0);

    if (total === 0) return [];

    let offset = 0;
    let paletteIndex = 0;

    return props.progress.columns
        .filter((column) => column.count > 0)
        .map((column) => {
            const fraction = column.count / total;
            const length = fraction * CIRCUMFERENCE;
            const segment = {
                name: column.name,
                count: column.count,
                percentage: Math.round(fraction * 100),
                colour: column.is_done ? DONE : PALETTE[paletteIndex++ % PALETTE.length],
                dash: `${length} ${CIRCUMFERENCE - length}`,
                offset: -offset,
            };

            offset += length;

            return segment;
        });
});
</script>

<template>
    <div class="task-card relative overflow-hidden rounded-3xl border p-5 sm:p-7">
        <div class="relative flex items-start justify-between gap-3">
            <div>
                <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.14em] uppercase">Progress</p>
                <h3 class="mt-1.5 text-[19px] font-semibold tracking-[-0.02em]">Task completion</h3>
            </div>

            <Link
                v-if="href"
                :href="href"
                class="border-border/70 bg-background/70 text-muted-foreground hover:text-foreground hover:border-foreground/25 grid size-9 shrink-0 place-items-center rounded-full border backdrop-blur transition-all hover:-translate-y-0.5"
                aria-label="Open the board"
            >
                <ArrowUpRight class="size-4" />
            </Link>
        </div>

        <div v-if="progress.total > 0" class="relative mt-6 flex flex-col items-center gap-7 sm:flex-row sm:gap-9">
            <!-- Ring: the whole board at a glance -->
            <div class="relative shrink-0">
                <svg viewBox="0 0 128 128" class="size-[152px] -rotate-90">
                    <circle cx="64" cy="64" :r="RADIUS" fill="none" stroke="currentColor" class="text-muted/60" stroke-width="14" />
                    <circle
                        v-for="segment in segments"
                        :key="segment.name"
                        cx="64"
                        cy="64"
                        :r="RADIUS"
                        fill="none"
                        :stroke="segment.colour"
                        stroke-width="14"
                        stroke-linecap="round"
                        :stroke-dasharray="segment.dash"
                        :stroke-dashoffset="segment.offset"
                        class="transition-all duration-700"
                    />
                </svg>

                <div class="absolute inset-0 grid place-items-center">
                    <div class="text-center">
                        <p class="text-[34px] leading-none font-semibold tracking-[-0.03em] tabular-nums">
                            {{ progress.completion_percentage }}<span class="text-[0.45em] font-medium">%</span>
                        </p>
                        <p class="text-muted-foreground mt-1 text-[11px] font-medium">complete</p>
                    </div>
                </div>
            </div>

            <!-- Legend + the two numbers that matter -->
            <div class="min-w-0 flex-1">
                <ul class="space-y-2.5">
                    <li v-for="segment in segments" :key="segment.name" class="flex items-center gap-2.5">
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ background: segment.colour }"></span>
                        <span class="min-w-0 flex-1 truncate text-[13px] font-medium">{{ segment.name }}</span>
                        <span class="text-muted-foreground shrink-0 text-[12.5px] tabular-nums">
                            {{ segment.count }} · {{ segment.percentage }}%
                        </span>
                    </li>
                </ul>

                <div class="border-border/70 mt-5 grid grid-cols-2 gap-3 border-t pt-4">
                    <div>
                        <p class="text-muted-foreground text-[11px] font-medium">Open</p>
                        <p class="mt-0.5 text-[17px] font-semibold tabular-nums">{{ progress.open }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-[11px] font-medium">Overdue</p>
                        <p class="mt-0.5 text-[17px] font-semibold tabular-nums" :class="progress.overdue > 0 ? 'text-rose-500' : ''">
                            {{ progress.overdue }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <p v-else class="text-muted-foreground relative py-10 text-center text-xs">
            No tasks yet. Create a project and add your first task to see progress here.
        </p>
    </div>
</template>

<style scoped>
/*
 * A soft wash rather than a flat panel — the same lime/indigo pairing the
 * landing page uses, at a fraction of the strength so it reads as depth
 * instead of decoration.
 */
.task-card {
    background:
        radial-gradient(120% 90% at 100% 0%, rgba(54, 90, 255, 0.07), transparent 60%),
        radial-gradient(90% 80% at 0% 100%, rgba(163, 230, 53, 0.12), transparent 60%), var(--color-card);
}

:global(.dark) .task-card {
    background:
        radial-gradient(120% 90% at 100% 0%, rgba(54, 90, 255, 0.14), transparent 60%),
        radial-gradient(90% 80% at 0% 100%, rgba(163, 230, 53, 0.09), transparent 60%), var(--color-card);
}
</style>
