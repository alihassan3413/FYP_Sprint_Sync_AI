<script setup lang="ts">
import { computed } from 'vue';

type Trend = 'up' | 'down' | 'flat' | null;

interface Props {
  label: string;
  value: string | number;
  /** Subtext shown next to the value */
  hint?: string;
  /** Trend direction — colors the hint */
  trend?: Trend;
  /** Optional fraction of total (e.g. "5 of 9") shown as faint subscript */
  fraction?: string;
}

const props = withDefaults(defineProps<Props>(), {
  trend: null,
});

const trendClasses = computed(() => {
  if (props.trend === 'up') return 'text-emerald-600 dark:text-emerald-400';
  if (props.trend === 'down') return 'text-rose-600 dark:text-rose-400';
  return 'text-muted-foreground';
});
</script>

<template>
  <div
    class="group relative rounded-xl border bg-card p-4 shadow-sm transition-colors hover:border-foreground/15"
  >
    <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-[0.06em] text-muted-foreground">
      <slot name="icon" />
      <span>{{ label }}</span>
    </div>

    <div class="mt-2 flex items-baseline gap-2">
      <span class="text-2xl font-semibold tracking-tight tabular-nums">
        {{ value }}
      </span>
      <span v-if="fraction" class="text-xs text-muted-foreground tabular-nums">
        {{ fraction }}
      </span>
      <span v-if="hint" :class="['text-xs font-medium', trendClasses]">
        {{ hint }}
      </span>
    </div>
  </div>
</template>
