<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { LucideLayoutGrid, Plus, Sparkles } from 'lucide-vue-next';

import { Input } from '@/components/ui/input';

interface Props {
  /** Suggestion chips shown above the bar */
  suggestions?: string[];
  /** Placeholder when input is empty */
  placeholder?: string;
  /** Hide the dock entirely (e.g. on auth pages) */
  hidden?: boolean;
}

withDefaults(defineProps<Props>(), {
  suggestions: () => [],
  placeholder: 'Ask your personal AI assistant',
  hidden: false,
});

const emit = defineEmits<{
  (e: 'submit', prompt: string): void;
}>();

const inputRef = ref<InstanceType<typeof Input> | null>(null);
const value = ref('');
const isFocused = ref(false);

const isActive = computed(
  () => isFocused.value || value.value.trim().length > 0,
);

function focusInput() {
  // shadcn Input forwards $el to the underlying <input>
  const el = (inputRef.value as unknown as { $el?: HTMLInputElement })?.$el;
  el?.focus();
}

function submit() {
  const prompt = value.value.trim();
  if (!prompt) return;
  emit('submit', prompt);
  value.value = '';
}

function selectSuggestion(text: string) {
  value.value = text;
  nextTick(() => focusInput());
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    submit();
  }
}
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    leave-active-class="transition-all duration-200 ease-in"
    enter-from-class="opacity-0 translate-y-4"
    leave-to-class="opacity-0 translate-y-4"
  >
    <div
      v-if="!hidden"
      role="region"
      aria-label="AI assistant"
      :class="[
        // Positioning — fixed dock, bottom-center of viewport
        'fixed bottom-6 left-1/2 z-50 -translate-x-1/2',

        // Sizing — exact spec width, responsive on mobile
        'w-125.75 max-w-[calc(100vw-2rem)]',

        // Layout — vertical stack of chips + bar
        'flex flex-col gap-2.5 p-2.5',

        // Glass effect — exact spec values
        'rounded-t-[30px] rounded-b-[35px]',
        'bg-black/15 backdrop-blur-[10.5px]',

        // Subtle inner highlight + outer shadow
        'shadow-[0_8px_32px_rgba(0,0,0,0.12),inset_0_1px_0_rgba(255,255,255,0.08)]',

        // Active state — slightly heavier shadow
        isActive && 'shadow-[0_12px_40px_rgba(0,0,0,0.18),inset_0_1px_0_rgba(255,255,255,0.12)]',

        // Smooth transitions
        'transition-shadow duration-200 ease-out',

        // Mobile bottom inset
        'max-sm:bottom-4',
      ]"
    >
      <div
        v-if="suggestions.length > 0"
        class="flex gap-2 px-2 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:[scrollbar-width:none] sm:flex-wrap"
      >
        <button
          v-for="(s, i) in suggestions"
          :key="i"
          type="button"
          class="group inline-flex h-7.5 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-black/10 px-3.5 pr-1.5 text-[12.5px] text-white/70  transition-colors hover:bg-black/20 hover:text-white/90"
          @click="selectSuggestion(s)"
        >
          <span class="truncate">{{ s }}</span>
          <span
            class="inline-flex size-4 items-center justify-center rounded-full bg-white/15 text-white/85"
          >
            <Plus class="size-2.5" :stroke-width="2.5" />
          </span>
        </button>
      </div>

      <!-- Main bar -->
     <div
        class="flex items-center px-2 py-1.75 justify-between rounded-4xl bg-[rgba(34,34,34,0.15)] text-[12.5px] text-white/70"
        @click="focusInput"
      >
        <div
          class="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#365AFF]"
        >
          <Sparkles class="size-4 text-white" :stroke-width="2" />
        </div>

        <!-- shadcn Input — overridden to fit dark glass theme -->
        <Input
          ref="inputRef"
          v-model="value"
          type="text"
          :placeholder="placeholder"
          aria-label="Ask the AI assistant"
          class="h-auto! flex-1 text-[16px] border-0! text-center font-normal bg-transparent! p-0! tracking-tight text-white placeholder:text-white focus-visible:ring-0! focus-visible:ring-offset-0!"
          @focus="isFocused = true"
          @blur="isFocused = false"
          @keydown="onKeydown"
        />

        <!-- Right action button -->
        <button
          type="button"
          class="flex size-11 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-all hover:bg-white/20 active:scale-95"
          :aria-label="value ? 'Send message' : 'Open assistant menu'"
          @click.stop="submit"
        >
          <LucideLayoutGrid class="size-4 fill-current" :stroke-width="2.2" />
        </button>
      </div>
    </div>
  </Transition>
</template>
