<script setup lang="ts">
import { computed } from 'vue';

type Variant =
  | 'neutral'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info'
  | 'purple'
  | 'pink'
  | 'teal'
  | 'outline';

type Size = 'sm' | 'md';

interface Props {
  variant?: Variant;
  size?: Size;
  /** Show a small pulsing or static dot before the label */
  dot?: boolean;
  /** Capitalize first letter of slot text via class */
  capitalize?: boolean;
  /** Pulse the dot (for live/active states) */
  pulse?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'neutral',
  size: 'sm',
  dot: false,
  capitalize: false,
  pulse: false,
});

// Tokens chosen from the same palette as the design system, with proper
// dark-mode equivalents using Tailwind's dark: variants.
const variantClasses = computed(() => ({
  neutral:
    'bg-muted text-muted-foreground',
  success:
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
  warning:
    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
  danger:
    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
  info:
    'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400',
  purple:
    'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
  pink:
    'bg-pink-50 text-pink-700 dark:bg-pink-500/10 dark:text-pink-300',
  teal:
    'bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300',
  outline:
    'border border-border text-foreground',
}[props.variant]));

const dotColor = computed(() => ({
  neutral: 'bg-muted-foreground/60',
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-rose-500',
  info: 'bg-sky-500',
  purple: 'bg-violet-500',
  pink: 'bg-pink-500',
  teal: 'bg-teal-500',
  outline: 'bg-foreground/60',
}[props.variant]));

const sizeClasses = computed(() => ({
  sm: 'h-[22px] px-2 text-[11px] gap-1.5',
  md: 'h-6 px-2.5 text-xs gap-1.5',
}[props.size]));
</script>

<template>
  <span
    :class="[
      'inline-flex items-center rounded-md font-medium leading-none whitespace-nowrap',
      sizeClasses,
      variantClasses,
      capitalize && 'capitalize',
    ]"
  >
    <span v-if="dot" class="relative flex shrink-0">
      <span
        v-if="pulse"
        :class="['absolute inline-flex h-full w-full animate-ping rounded-full opacity-60', dotColor]"
      />
      <span :class="['relative inline-block size-1.5 rounded-full', dotColor]" />
    </span>

    <slot name="icon" />
    <slot />
  </span>
</template>
