<script setup lang="ts">
/**
 * Burndown for a running sprint: how much work is actually left each day
 * against the straight line the sprint would follow if it burned down evenly.
 *
 * Drawn as inline SVG on a 0-100 viewBox so it scales to whatever width the
 * card gives it, and picks up theme colours through currentColor.
 */
import type { SprintBurndownPoint } from '@/lib/sprints';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        points: SprintBurndownPoint[];
        totalDays: number;
        height?: number;
    }>(),
    { height: 96 },
);

const VIEW_W = 100;
const VIEW_H = 40;

const ceiling = computed(() => {
    const highest = Math.max(1, ...props.points.map((point) => Math.max(point.remaining, point.ideal)));

    return highest;
});

/** X is the sprint day index, so a half-finished sprint draws on the left half. */
const lastDayIndex = computed(() => Math.max(1, props.totalDays - 1));

function toX(index: number): number {
    return (index / lastDayIndex.value) * VIEW_W;
}

function toY(value: number): number {
    return VIEW_H - (value / ceiling.value) * VIEW_H;
}

const actualLine = computed(() => props.points.map((point, index) => `${toX(index)},${toY(point.remaining)}`).join(' '));

/** The ideal line always spans the whole sprint, even before the days are gone. */
const idealLine = computed(() => {
    const start = props.points[0]?.ideal ?? ceiling.value;

    return `0,${toY(start)} ${VIEW_W},${toY(0)}`;
});

const actualArea = computed(() => {
    if (props.points.length === 0) return '';

    const lastX = toX(props.points.length - 1);

    return `0,${VIEW_H} ${actualLine.value} ${lastX},${VIEW_H}`;
});

const lastPoint = computed(() => props.points[props.points.length - 1] ?? null);

const isAhead = computed(() => (lastPoint.value === null ? false : lastPoint.value.remaining <= lastPoint.value.ideal));
</script>

<template>
    <div v-if="points.length > 0" class="flex flex-col gap-1.5">
        <svg
            :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`"
            preserveAspectRatio="none"
            :style="{ height: `${height}px` }"
            class="w-full overflow-visible"
            role="img"
            aria-label="Sprint burndown"
        >
            <polygon :points="actualArea" :class="isAhead ? 'fill-emerald-500/10' : 'fill-amber-500/10'" vector-effect="non-scaling-stroke" />

            <polyline
                :points="idealLine"
                fill="none"
                stroke="currentColor"
                stroke-width="1"
                stroke-dasharray="3 3"
                vector-effect="non-scaling-stroke"
                class="text-muted-foreground/50"
            />

            <polyline
                :points="actualLine"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
                :class="isAhead ? 'text-emerald-500' : 'text-amber-500'"
            />
        </svg>

        <div class="text-muted-foreground flex items-center justify-between text-[10px]">
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-block h-0.5 w-3 rounded-full" :class="isAhead ? 'bg-emerald-500' : 'bg-amber-500'" />
                Remaining
            </span>

            <span class="inline-flex items-center gap-1.5">
                <span class="border-muted-foreground/50 inline-block w-3 border-t border-dashed" />
                Ideal
            </span>

            <span v-if="lastPoint" class="tabular-nums"> {{ lastPoint.remaining }} left on day {{ points.length }} </span>
        </div>
    </div>
</template>
