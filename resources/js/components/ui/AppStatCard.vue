<script setup lang="ts">
import { computed } from 'vue';

type Trend = 'up' | 'down' | 'flat' | null;

/**
 * Colour treatments borrowed from the landing page. `default` keeps the plain
 * card every other page already uses, so adding a tone here is opt-in.
 */
type Tone = 'default' | 'lime' | 'indigo' | 'lavender' | 'ink';

interface Props {
  label: string;
  value: string | number;
  /** Subtext shown next to the value */
  hint?: string;
  /** Trend direction — colors the hint */
  trend?: Trend;
  /** Optional fraction of total (e.g. "5 of 9") shown as faint subscript */
  fraction?: string;
  /** Colour treatment. Leave unset for the standard card. */
  tone?: Tone;
}

const props = withDefaults(defineProps<Props>(), {
  trend: null,
  tone: 'default',
});

const toneClasses: Record<Tone, string> = {
  default: 'border bg-card shadow-sm hover:border-foreground/15',
  lime: 'border-transparent bg-[#BAFF1A] text-[#0B0B0F]',
  indigo: 'border-transparent bg-[#365AFF] text-white',
  lavender: 'border-transparent bg-[#E4E3FF] text-[#0B0B0F] dark:bg-indigo-500/20 dark:text-foreground',
  ink: 'border-transparent bg-foreground text-background',
};

/** Labels and hints need their contrast from the tile, not from the theme. */
const mutedOnTone = computed(() =>
  props.tone === 'default' ? 'text-muted-foreground' : 'opacity-60',
);

const trendClasses = computed(() => {
  if (props.trend === 'up') return 'text-emerald-600 dark:text-emerald-400';
  if (props.trend === 'down') return 'text-rose-600 dark:text-rose-400';
  return 'text-muted-foreground';
});
</script>

<template>
  <div
    class="group relative rounded-2xl border p-4 transition-all hover:-translate-y-0.5"
    :class="toneClasses[tone]"
  >
    <div
      class="flex items-center gap-1.5 text-[10.5px] font-semibold uppercase tracking-[0.12em]"
      :class="mutedOnTone"
    >
      <slot name="icon" />
      <span>{{ label }}</span>
    </div>

    <div class="mt-2 flex items-baseline gap-2">
      <span class="text-[26px] font-semibold leading-none tracking-[-0.025em] tabular-nums">
        {{ value }}
      </span>
      <span v-if="fraction" class="text-xs tabular-nums" :class="mutedOnTone">
        {{ fraction }}
      </span>
      <span
        v-if="hint"
        class="text-xs font-semibold"
        :class="tone === 'default' ? trendClasses : mutedOnTone"
      >
        {{ hint }}
      </span>
    </div>
  </div>
</template>
