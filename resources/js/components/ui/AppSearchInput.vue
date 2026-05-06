<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

interface Props {
  placeholder?: string;
  /** Display a keyboard shortcut hint (e.g. "⌘K"). Pass empty string to hide. */
  shortcut?: string;
  /** Bind the shortcut globally — focuses the input on press */
  bindShortcut?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Search…',
  shortcut: '⌘K',
  bindShortcut: true,
});

const model = defineModel<string>({ default: '' });
const inputRef = ref<HTMLInputElement | null>(null);

function clear() {
  model.value = '';
  inputRef.value?.focus();
}

function onKeydown(e: KeyboardEvent) {
  if (!props.bindShortcut) return;
  // ⌘K / Ctrl+K
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    inputRef.value?.focus();
    inputRef.value?.select();
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
  <div class="relative w-full">
    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
      <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="size-4"
      >
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
      </svg>
    </span>

    <input
      ref="inputRef"
      v-model="model"
      type="text"
      :placeholder="placeholder"
      class="h-9 w-full rounded-lg border border-input bg-muted/40 pl-9 pr-16 text-sm transition-colors placeholder:text-muted-foreground focus:bg-background focus:outline-none focus:ring-2 focus:ring-ring/40"
    />

    <button
      v-if="model"
      type="button"
      class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      aria-label="Clear search"
      @click="clear"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="size-3.5"
      >
        <path d="M18 6 6 18" />
        <path d="m6 6 12 12" />
      </svg>
    </button>

    <kbd
      v-else-if="shortcut"
      class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 select-none items-center gap-0.5 rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground sm:inline-flex"
    >
      {{ shortcut }}
    </kbd>
  </div>
</template>
