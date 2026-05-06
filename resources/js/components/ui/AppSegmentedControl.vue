<script setup lang="ts">
interface Option {
  value: string;
  label: string;
  count?: number;
}

interface Props {
  options: Option[];
  /** Show counts as small pills next to labels */
  showCounts?: boolean;
}

withDefaults(defineProps<Props>(), {
  showCounts: true,
});

const model = defineModel<string>({ required: true });
</script>

<template>
  <div
    role="tablist"
    class="inline-flex items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5"
  >
    <button
      v-for="opt in options"
      :key="opt.value"
      type="button"
      role="tab"
      :aria-selected="model === opt.value"
      :class="[
        'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
        model === opt.value
          ? 'bg-background text-foreground shadow-sm ring-1 ring-border/60'
          : 'text-muted-foreground hover:text-foreground',
      ]"
      @click="model = opt.value"
    >
      {{ opt.label }}
      <span
        v-if="showCounts && typeof opt.count === 'number'"
        :class="[
          'tabular-nums rounded px-1 py-0 text-[10px]',
          model === opt.value ? 'bg-muted text-muted-foreground' : 'bg-transparent',
        ]"
      >
        {{ opt.count }}
      </span>
    </button>
  </div>
</template>
