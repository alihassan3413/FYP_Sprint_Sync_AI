<script setup lang="ts">
import { computed } from 'vue';

type Trend = 'up' | 'down' | 'flat' | null;

/**
 * Colour treatments borrowed from the landing page. `default` keeps the plain
 * card every other page already uses, so adding a tone here is opt-in.
 */
type Tone = 'default' | 'lime' | 'indigo' | 'lavender' | 'rose' | 'neutralSoft';

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

/**
 * Tints, not fills. A saturated tile competes with the number printed on it;
 * a wash lets the figure stay the loudest thing in the card while still giving
 * each metric its own identity. The accent lives in the top-right corner glow.
 */
const toneClasses: Record<Tone, string> = {
  default: 'border-border/70 bg-card',
  lime: 'border-lime-500/25 tile-lime',
  indigo: 'border-indigo-500/25 tile-indigo',
  lavender: 'border-violet-500/25 tile-lavender',
  rose: 'border-rose-500/25 tile-rose',
  neutralSoft: 'border-border/70 tile-neutral',
};

/** Labels stay muted on every tone — the tint is behind them, not under them. */
const mutedOnTone = 'text-muted-foreground';

const trendClasses = computed(() => {
  if (props.trend === 'up') return 'text-emerald-600 dark:text-emerald-400';
  if (props.trend === 'down') return 'text-rose-600 dark:text-rose-400';
  return 'text-muted-foreground';
});
</script>

<template>
  <div
    class="group relative overflow-hidden rounded-3xl border p-4 transition-all duration-200 hover:-translate-y-0.5 sm:p-5"
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
      <span class="text-[27px] font-semibold leading-none tracking-[-0.03em] tabular-nums">
        {{ value }}
      </span>
      <span v-if="fraction" class="text-xs tabular-nums" :class="mutedOnTone">
        {{ fraction }}
      </span>
      <span v-if="hint" class="text-xs font-medium" :class="tone === 'default' ? trendClasses : mutedOnTone">
        {{ hint }}
      </span>
    </div>
  </div>
</template>

<style scoped>
/*
 * Each tint is a single corner glow over the card colour, so the tiles read as
 * one family in both themes rather than five different backgrounds.
 */
.tile-lime {
  background: radial-gradient(120% 100% at 100% 0%, rgba(163, 230, 53, 0.18), transparent 62%), var(--color-card);
}

.tile-indigo {
  background: radial-gradient(120% 100% at 100% 0%, rgba(54, 90, 255, 0.14), transparent 62%), var(--color-card);
}

.tile-lavender {
  background: radial-gradient(120% 100% at 100% 0%, rgba(167, 139, 250, 0.18), transparent 62%), var(--color-card);
}

.tile-rose {
  background: radial-gradient(120% 100% at 100% 0%, rgba(251, 113, 133, 0.16), transparent 62%), var(--color-card);
}

.tile-neutral {
  background: radial-gradient(120% 100% at 100% 0%, rgba(120, 125, 145, 0.12), transparent 62%), var(--color-card);
}
</style>
