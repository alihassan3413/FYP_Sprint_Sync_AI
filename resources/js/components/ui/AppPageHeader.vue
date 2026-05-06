<script setup lang="ts">
interface Props {
  /** Small label above the title (e.g. "WORKSPACE", "PROJECT") */
  eyebrow?: string;
  title: string;
  description?: string;
}

defineProps<Props>();
</script>

<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0 flex-1">
      <p
        v-if="eyebrow"
        class="mb-1.5 text-[11px] font-medium uppercase tracking-[0.08em] text-muted-foreground"
      >
        {{ eyebrow }}
      </p>

      <h1 class="text-2xl font-semibold tracking-tight text-foreground sm:text-[28px]">
        {{ title }}
      </h1>

      <p
        v-if="description"
        class="mt-1 max-w-2xl text-sm text-muted-foreground"
      >
        {{ description }}
      </p>

      <div v-if="$slots.meta" class="mt-3 flex flex-wrap items-center gap-2">
        <slot name="meta" />
      </div>
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
