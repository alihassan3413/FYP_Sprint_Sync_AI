<script setup lang="ts">
import { motion } from 'motion-v';
import { ChevronUp, Plus, Sparkles, X } from 'lucide-vue-next';

interface Props {
  suggestions?: string[];
  placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
  suggestions: () => [],
  placeholder: 'Ask your personal AI assistant',
});

const { inputValue, submit, expand, collapse, messages } = useAiAssistant();

const inputRef = ref<InstanceType<typeof Input> | null>(null);
const isFocused = ref(false);

const isActive = computed(
  () => isFocused.value || inputValue.value.trim().length > 0,
);

function focusInput() {
  const el = (inputRef.value as unknown as { $el?: HTMLInputElement })?.$el;
  el?.focus();
}

function onSubmitClick() {
  const prompt = inputValue.value.trim();
  if (!prompt) return;
  submit(prompt);
}

function selectSuggestion(text: string) {
  inputValue.value = text;
  nextTick(() => focusInput());
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    onSubmitClick();
  }
}

defineExpose({ focusInput });
</script>

<template>
  <motion.div
    layout-id="assistant-shell"
    :transition="{
      layout: { type: 'spring', stiffness: 360, damping: 34, mass: 0.9 },
      opacity: { duration: 0.2 },
    }"
    :initial="{ opacity: 0 }"
    :animate="{ opacity: 1 }"
    :exit="{ opacity: 0, transition: { duration: 0.15 } }"
    role="region"
    aria-label="AI assistant"
    :class="[
      // Centered bottom — dock lives mid-screen
      'fixed bottom-6 left-1/2 z-50 -translate-x-1/2',

      'w-[420px] max-w-[calc(100vw-2rem)]',
      'flex flex-col gap-2.5 p-2.5',
      'rounded-t-[30px] rounded-b-[35px]',
      'bg-black/15 backdrop-blur-[10.5px]',
      'shadow-[0_8px_32px_rgba(0,0,0,0.12),inset_0_1px_0_rgba(255,255,255,0.08)]',
      isActive && 'shadow-[0_12px_40px_rgba(0,0,0,0.18),inset_0_1px_0_rgba(255,255,255,0.12)]',
      'transition-shadow duration-200 ease-out',
      'max-sm:bottom-4 max-sm:left-4 max-sm:right-4 max-sm:w-auto max-sm:translate-x-0',
    ]"
  >
    <!-- Top control row -->
    <div class="flex items-center justify-between px-2 pt-0.5">
      <button
        v-if="messages.length > 0"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-full bg-black/15 px-2.5 py-1 text-[11px] font-medium text-white/70 transition hover:bg-black/25 hover:text-white"
        aria-label="Expand to full chat"
        @click="expand"
      >
        <ChevronUp class="size-3" :stroke-width="2.5" />
        <span>{{ messages.length }} {{ messages.length === 1 ? 'message' : 'messages' }}</span>
      </button>
      <span v-else />

      <button
        type="button"
        class="grid size-6 place-items-center rounded-full text-white/50 transition hover:bg-white/10 hover:text-white"
        aria-label="Minimize assistant"
        @click="collapse"
      >
        <X class="size-3.5" :stroke-width="2.5" />
      </button>
    </div>

    <!-- Suggestion chips -->
    <div
      v-if="props.suggestions.length > 0"
      class="flex gap-2 px-2 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:[scrollbar-width:none] sm:flex-wrap"
    >
      <button
        v-for="(s, i) in props.suggestions"
        :key="i"
        type="button"
        class="group inline-flex h-7.5 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-white/10 bg-black/10 px-3.5 pr-1.5 text-[12.5px] text-white/70 transition-colors hover:bg-black/20 hover:text-white/90"
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

    <!-- Main input bar -->
    <div
      class="flex items-center justify-between rounded-4xl bg-[rgba(34,34,34,0.15)] px-2 py-1.75 text-[12.5px] text-white/70"
      @click="focusInput"
    >
      <motion.div
        layout-id="assistant-icon"
        :transition="{ type: 'spring', stiffness: 400, damping: 30 }"
        class="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#365AFF]"
      >
        <Sparkles class="size-4 text-white" :stroke-width="2" />
      </motion.div>

      <Input
        ref="inputRef"
        v-model="inputValue"
        type="text"
        :placeholder="props.placeholder"
        aria-label="Ask the AI assistant"
        class="h-auto! flex-1 border-0! bg-transparent! p-0! text-center text-[16px] font-normal tracking-tight text-white placeholder:text-white focus-visible:ring-0! focus-visible:ring-offset-0!"
        @focus="isFocused = true"
        @blur="isFocused = false"
        @keydown="onKeydown"
      />

      <button
        type="button"
        class="flex size-11 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-all hover:bg-white/20 active:scale-95"
        :aria-label="inputValue ? 'Send message' : 'Expand chat'"
        @click.stop="inputValue.trim() ? onSubmitClick() : expand()"
      >
        <ChevronUp v-if="!inputValue.trim()" class="size-4" :stroke-width="2.2" />
        <svg
          v-else
          xmlns="http://www.w3.org/2000/svg"
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path d="M5 12h14M13 5l7 7-7 7" />
        </svg>
      </button>
    </div>
  </motion.div>
</template>
