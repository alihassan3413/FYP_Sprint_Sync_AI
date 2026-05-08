<script setup lang="ts">
/**
 * SeatUsageCard — shows seat consumption with a progress bar.
 *
 * Self-contained: pass `used` and `total`, it renders. Color shifts to amber
 * when nearly full and rose when over the limit.
 *
 * Reusable on Teams page and the Billing/Settings page.
 */

import { CreditCard } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    used: number;
    total: number;
    /** Threshold at which the bar turns amber (default 80%) */
    warnAt?: number;
}

const props = withDefaults(defineProps<Props>(), {
    warnAt: 0.8,
});

const pct = computed(() => {
    if (props.total <= 0) return 0;
    return Math.min(100, Math.round((props.used / props.total) * 100));
});

const barColor = computed(() => {
    if (props.used >= props.total) return 'bg-rose-500';
    if (props.used / props.total >= props.warnAt) return 'bg-amber-500';
    return 'bg-foreground/80';
});
</script>

<template>
    <div class="group bg-card hover:border-foreground/15 relative rounded-xl border p-4 shadow-sm transition-colors">
        <div class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[0.06em] uppercase">
            <CreditCard class="size-3.5" />
            <span>Seats used</span>
        </div>

        <div class="mt-2 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tracking-tight tabular-nums">
                {{ used }}
            </span>
            <span class="text-muted-foreground text-xs tabular-nums"> / {{ total }} </span>
        </div>

        <div class="bg-muted mt-2.5 h-1 w-full overflow-hidden rounded-full">
            <div :class="['h-full rounded-full transition-all', barColor]" :style="{ width: `${pct}%` }" />
        </div>
    </div>
</template>
