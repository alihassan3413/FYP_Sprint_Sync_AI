<script setup lang="ts">
import { computed } from 'vue';

type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
type StatusKind = 'active' | 'away' | 'offline' | 'busy' | null;

interface Props {
  /** User name — used to compute initials when no image is provided */
  name?: string;
  /** Optional image URL */
  src?: string | null;
  /** Email — used as a stable hash for color when name is missing */
  email?: string;
  /** Size token */
  size?: AvatarSize;
  /** Presence indicator */
  status?: StatusKind;
  /** Force a specific color index (0-7). When omitted, derived from name/email. */
  colorIndex?: number;
  /** Show a dashed placeholder ring (used for invited / empty seats) */
  placeholder?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
  status: null,
  placeholder: false,
});

// 8 brand-safe palette stops. We deliberately avoid red/amber/green so they
// stay reserved for semantic UI (errors, warnings, success).
const palette = [
  { bg: '#534AB7', fg: '#ffffff' }, // purple
  { bg: '#0F6E56', fg: '#ffffff' }, // teal
  { bg: '#D85A30', fg: '#ffffff' }, // coral
  { bg: '#D4537E', fg: '#ffffff' }, // pink
  { bg: '#185FA5', fg: '#ffffff' }, // blue
  { bg: '#3C3489', fg: '#ffffff' }, // deep purple
  { bg: '#993C1D', fg: '#ffffff' }, // burnt coral
  { bg: '#0C447C', fg: '#ffffff' }, // deep blue
];

const initials = computed(() => {
  if (!props.name) return '?';
  const parts = props.name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
});

// Stable hash → consistent color per user.
const colorPair = computed(() => {
  if (typeof props.colorIndex === 'number') {
    return palette[props.colorIndex % palette.length];
  }
  const seed = (props.name || props.email || '').toLowerCase();
  let hash = 0;
  for (let i = 0; i < seed.length; i++) {
    hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
  }
  return palette[hash % palette.length];
});

const sizeClasses = computed(() => ({
  xs: 'size-6 text-[10px]',
  sm: 'size-7 text-[11px]',
  md: 'size-8 text-xs',
  lg: 'size-10 text-sm',
  xl: 'size-12 text-base',
}[props.size]));

const dotClasses = computed(() => ({
  xs: 'size-1.5 ring-[1.5px]',
  sm: 'size-2 ring-2',
  md: 'size-2.5 ring-2',
  lg: 'size-3 ring-2',
  xl: 'size-3.5 ring-[3px]',
}[props.size]));

const statusBg = computed(() => ({
  active: 'bg-emerald-500',
  away: 'bg-amber-400',
  busy: 'bg-rose-500',
  offline: 'bg-muted-foreground/40',
}[props.status as Exclude<StatusKind, null>] || ''));
</script>

<template>
  <div class="relative inline-flex shrink-0">
    <span
      v-if="placeholder"
      :class="[
        sizeClasses,
        'inline-flex items-center justify-center rounded-full border border-dashed border-border bg-muted/40 text-muted-foreground',
      ]"
    >
      <slot name="placeholder">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          class="size-1/2"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75Z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="m3 7 9 6 9-6" />
        </svg>
      </slot>
    </span>

    <img
      v-else-if="src"
      :src="src"
      :alt="name || ''"
      :class="[sizeClasses, 'rounded-full object-cover ring-1 ring-border/60']"
      loading="lazy"
    />

    <span
      v-else
      :class="[sizeClasses, 'inline-flex items-center justify-center rounded-full font-medium tracking-wide select-none ring-1 ring-black/5']"
      :style="{ backgroundColor: colorPair.bg, color: colorPair.fg }"
      :aria-label="name"
    >
      {{ initials }}
    </span>

    <span
      v-if="status"
      :class="[
        dotClasses,
        statusBg,
        'absolute -bottom-0.5 -right-0.5 rounded-full ring-background',
      ]"
      :aria-label="`Status: ${status}`"
    />
  </div>
</template>
