<script setup lang="ts">
import { computed, type Component } from 'vue';

interface Option {
  value: string;
  label: string;
  description?: string;
  icon?: Component;
  badge?: string;
  disabled?: boolean;
}

interface Props {
  options: Option[];
  /** Layout direction */
  direction?: 'vertical' | 'horizontal';
  /** Color accent for the selected state */
  accent?: 'violet' | 'blue' | 'foreground';
}

const props = withDefaults(defineProps<Props>(), {
  direction: 'vertical',
  accent: 'foreground',
});

const model = defineModel<string>({ required: true });

const accentClasses = computed(() => ({
  violet: {
    border: 'border-violet-500 dark:border-violet-400',
    bg: 'bg-violet-50/60 dark:bg-violet-500/[0.08]',
    dot: 'bg-violet-600 dark:bg-violet-400',
    ring: 'border-violet-500 dark:border-violet-400',
    label: 'text-violet-900 dark:text-violet-100',
    desc: 'text-violet-700/80 dark:text-violet-300/80',
  },
  blue: {
    border: 'border-sky-500 dark:border-sky-400',
    bg: 'bg-sky-50/60 dark:bg-sky-500/[0.08]',
    dot: 'bg-sky-600 dark:bg-sky-400',
    ring: 'border-sky-500 dark:border-sky-400',
    label: 'text-sky-900 dark:text-sky-100',
    desc: 'text-sky-700/80 dark:text-sky-300/80',
  },
  foreground: {
    border: 'border-foreground/80 dark:border-foreground/70',
    bg: 'bg-muted/40',
    dot: 'bg-foreground',
    ring: 'border-foreground/80',
    label: 'text-foreground',
    desc: 'text-muted-foreground',
  },
}[props.accent]));
</script>

<template>
  <div
    role="radiogroup"
    :class="[
      'flex gap-2',
      direction === 'vertical' ? 'flex-col' : 'flex-row',
    ]"
  >
    <label
      v-for="opt in options"
      :key="opt.value"
      :class="[
        'group relative flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all',
        model === opt.value
          ? `${accentClasses.border} ${accentClasses.bg} ring-1 ${accentClasses.border}`
          : 'border-border bg-card hover:border-foreground/20 hover:bg-muted/30',
        opt.disabled && 'cursor-not-allowed opacity-50',
      ]"
    >
      <input
        type="radio"
        class="sr-only"
        :value="opt.value"
        v-model="model"
        :disabled="opt.disabled"
      />

      <span
        :class="[
          'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-[1.5px] transition-colors',
          model === opt.value
            ? accentClasses.ring
            : 'border-muted-foreground/40 group-hover:border-foreground/40',
        ]"
      >
        <span
          v-if="model === opt.value"
          :class="['size-2 rounded-full', accentClasses.dot]"
        />
      </span>

      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <component
            v-if="opt.icon"
            :is="opt.icon"
            :class="[
              'size-3.5',
              model === opt.value ? accentClasses.label : 'text-muted-foreground',
            ]"
          />
          <span
            :class="[
              'text-[13px] font-medium leading-none',
              model === opt.value ? accentClasses.label : 'text-foreground',
            ]"
          >
            {{ opt.label }}
          </span>
          <span
            v-if="opt.badge"
            class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
          >
            {{ opt.badge }}
          </span>
        </div>
        <p
          v-if="opt.description"
          :class="[
            'mt-1 text-[11.5px] leading-relaxed',
            model === opt.value ? accentClasses.desc : 'text-muted-foreground',
          ]"
        >
          {{ opt.description }}
        </p>
      </div>
    </label>
  </div>
</template>
