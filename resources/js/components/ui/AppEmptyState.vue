<script setup lang="ts">
interface Props {
  title: string;
  description?: string;
  /** Compact removes the heavy padding for inline use */
  compact?: boolean;
}

withDefaults(defineProps<Props>(), {
  compact: false,
});
</script>

<template>
  <div
    :class="[
      'flex flex-col items-center justify-center gap-3 text-center',
      compact ? 'px-6 py-10' : 'px-6 py-16',
    ]"
  >
    <div
      class="flex size-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/30 text-muted-foreground"
    >
      <slot name="icon">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
          class="size-5"
        >
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </slot>
    </div>

    <div class="max-w-sm space-y-1">
      <h3 class="text-sm font-semibold text-foreground">{{ title }}</h3>
      <p v-if="description" class="text-sm text-muted-foreground">
        {{ description }}
      </p>
    </div>

    <div v-if="$slots.actions" class="mt-2 flex items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
