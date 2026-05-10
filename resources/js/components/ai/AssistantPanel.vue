<script setup lang="ts">
import { ChevronDown, Plus, RotateCcw, Sparkles, X } from "lucide-vue-next";
import { motion } from "motion-v";
import MarkdownIt from "markdown-it";
import DOMPurify from "dompurify";

interface Props {
  suggestions?: string[];
  placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
  suggestions: () => [],
  placeholder: "Ask anything...",
});

const markdown = new MarkdownIt({
  html: false,
  linkify: true,
  breaks: true,
});

function renderMarkdown(content: string): string {
  return DOMPurify.sanitize(markdown.render(content));
}

function shouldShowThinking(msg: {
  role: string;
  isLoading?: boolean;
  content?: string;
  pendingTool?: unknown;
}) {
  return (
    msg.role === "assistant" && msg.isLoading && !msg.content?.trim() && !msg.pendingTool
  );
}

const {
  inputValue,
  messages,
  isStreaming,
  submit,
  openDock,
  collapse,
  clearConversation,
  confirmTool,
} = useAiAssistant();

const inputRef = ref<InstanceType<typeof Input> | null>(null);
const scrollRef = ref<HTMLElement | null>(null);
const isFocused = ref(false);

const hasMessages = computed(() => messages.value.length > 0);
const isActive = computed(() => isFocused.value || inputValue.value.trim().length > 0);

function focusInput() {
  const el = ((inputRef.value as unknown) as { $el?: HTMLInputElement })?.$el;
  el?.focus();
}

function onSubmitClick() {
  const prompt = inputValue.value.trim();
  if (!prompt || isStreaming.value) return;
  submit(prompt);
}

function selectSuggestion(text: string) {
  inputValue.value = text;
  nextTick(() => focusInput());
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === "Enter" && !e.shiftKey) {
    e.preventDefault();
    onSubmitClick();
  }
}

watch(
  () => messages.value.map((m) => m.content).join(""),
  () => {
    nextTick(() => {
      if (scrollRef.value) {
        scrollRef.value.scrollTop = scrollRef.value.scrollHeight;
      }
    });
  }
);
</script>

<template>
  <motion.div
    layout-id="assistant-shell"
    :transition="{
      layout: { type: 'spring', stiffness: 320, damping: 34, mass: 1 },
      opacity: { duration: 0.2 },
    }"
    :initial="{ opacity: 0 }"
    :animate="{ opacity: 1 }"
    :exit="{ opacity: 0, transition: { duration: 0.15 } }"
    role="dialog"
    aria-label="AI assistant chat"
    aria-modal="false"
    :class="[
      'fixed bottom-6 left-1/2 z-50 -translate-x-1/2',
      'w-[420px] max-w-[calc(100vw-2rem)]',
      'h-[600px] max-h-[calc(100vh-3rem)]',
      'flex flex-col gap-2.5 p-2.5',
      'rounded-t-[30px] rounded-b-[35px]',
      'bg-black/15 backdrop-blur-[10.5px]',
      'shadow-[0_8px_32px_rgba(0,0,0,0.12),inset_0_1px_0_rgba(255,255,255,0.08)]',
      isActive &&
        'shadow-[0_12px_40px_rgba(0,0,0,0.18),inset_0_1px_0_rgba(255,255,255,0.12)]',
      'transition-shadow duration-200 ease-out',
      'max-sm:right-4 max-sm:bottom-4 max-sm:left-4 max-sm:h-[calc(100vh-2rem)] max-sm:w-auto max-sm:translate-x-0',
    ]"
  >
    <!-- Top control row -->
    <div class="flex items-center justify-between px-3 pt-2">
      <!-- macOS-style controls -->
      <div class="flex items-center gap-1.5">
        <!-- Close -->
        <button
          type="button"
          class="group grid size-3.5 place-items-center rounded-full bg-[#ff5f57] shadow-sm ring-1 ring-black/10 transition hover:scale-110"
          aria-label="Close assistant"
          title="Close"
          @click="collapse"
        >
          <X
            class="size-2 opacity-0 transition group-hover:opacity-70"
            :stroke-width="3"
          />
        </button>

        <!-- Minimize -->
        <button
          type="button"
          class="group grid size-3.5 place-items-center rounded-full bg-[#ffbd2e] shadow-sm ring-1 ring-black/10 transition hover:scale-110"
          aria-label="Minimize to dock"
          title="Minimize"
          @click="openDock"
        >
          <span
            class="h-0.5 w-1.5 rounded-full bg-black/55 opacity-0 transition group-hover:opacity-70"
          />
        </button>

        <!-- New conversation -->
        <button
          v-if="hasMessages"
          type="button"
          class="group grid size-3.5 place-items-center rounded-full bg-[#28c840] shadow-sm ring-1 ring-black/10 transition hover:scale-110"
          aria-label="New conversation"
          title="New conversation"
          @click="clearConversation"
        >
          <RotateCcw
            class="size-2 opacity-0 transition group-hover:opacity-70"
            :stroke-width="3"
          />
        </button>

        <span
          v-else
          class="block size-3.5 rounded-full bg-white/12 ring-1 ring-white/10"
        />
      </div>
    </div>

    <!-- Conversation area -->
    <div
      ref="scrollRef"
      class="flex-1 overflow-y-auto overscroll-contain px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
    >
      <!-- Empty state -->
      <div
        v-if="!hasMessages"
        class="flex h-full flex-col items-center justify-center gap-3 px-4 text-center"
      >
        <div
          class="flex size-12 items-center justify-center rounded-full bg-custom-blue shadow-[0_8px_24px_rgba(54,90,255,0.35)]"
        >
          <Sparkles class="size-5 text-white" :stroke-width="2" />
        </div>
        <p class="text-[13px] tracking-tight text-white/70">How can I help?</p>
      </div>

      <!-- Messages -->
      <div v-else class="flex flex-col gap-2.5 py-1">
        <motion.div
          v-for="msg in messages"
          :key="msg.id"
          :initial="{ opacity: 0, y: 6 }"
          :animate="{ opacity: 1, y: 0 }"
          :transition="{ duration: 0.25, ease: [0.32, 0.72, 0, 1] }"
          :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']"
        >
          <div
            :class="[
              'max-w-[85%] rounded-2xl bg-[rgba(34,34,34,0.40)] px-4 py-2.5',
              msg.role === 'user' && 'bg-[rgba(54,90,255)]',
            ]"
          >
            <span
              :class="[
                'mb-0.5 block text-[9.5px] font-medium tracking-[0.1em] uppercase',
                msg.role === 'user' ? 'text-white' : 'text-[rgba(54,90,255)]',
              ]"
            >
              {{ msg.role === "user" ? "You" : "Assistant" }}
            </span>

            <div class="text-[13.5px] leading-relaxed tracking-tight text-white/90">
              <!-- Minimal thinking state: pure shimmer text -->
              <div
                v-if="shouldShowThinking(msg)"
                class="py-0.5"
                aria-label="Assistant is thinking"
              >
                <span class="thinking-shimmer">Thinking</span>
              </div>

              <!-- Normal streamed text -->
              <template v-else>
                <motion.div
                  v-if="msg.content"
                  :initial="{ opacity: 0, y: 3 }"
                  :animate="{ opacity: 1, y: 0 }"
                  :transition="{ duration: 0.16 }"
                  class="assistant-markdown"
                  v-html="renderMarkdown(msg.content)"
                />

                <span
                  v-if="msg.streaming && msg.content"
                  class="ml-0.5 inline-block h-3 w-0.5 translate-y-0.5 animate-pulse bg-white/70"
                  aria-hidden="true"
                />
              </template>
            </div>

            <!-- Pending tool call -->
            <div v-if="msg.pendingTool" class="mt-2 rounded-2xl bg-black/25 p-2.5">
              <p class="text-[11.5px] text-white/85">
                {{ msg.pendingTool.summary }}
              </p>

              <div class="mt-1.5 space-y-0.5">
                <div
                  v-for="(value, key) in msg.pendingTool.args"
                  :key="key"
                  class="flex gap-1.5 text-[10.5px]"
                >
                  <span class="text-white/50">{{ key }}:</span>
                  <span class="text-white/85">{{ value }}</span>
                </div>
              </div>

              <div class="mt-2 flex gap-1.5">
                <button
                  type="button"
                  :disabled="isStreaming"
                  class="rounded-full bg-custom-blue px-3 py-1 text-[11px] font-medium text-white transition hover:bg-[#2a4aef] disabled:cursor-not-allowed disabled:opacity-50"
                  @click="confirmTool(msg.pendingTool!.messageId, 'confirm')"
                >
                  Confirm
                </button>
                <button
                  type="button"
                  :disabled="isStreaming"
                  class="rounded-full bg-white/10 px-3 py-1 text-[11px] font-medium text-white/80 transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-50"
                  @click="confirmTool(msg.pendingTool!.messageId, 'reject')"
                >
                  Cancel
                </button>
              </div>
            </div>

            <div
              v-else-if="msg.toolStatus === 'rejected'"
              class="mt-2 text-[10.5px] text-white/50 italic"
            >
              Action canceled.
            </div>
          </div>
        </motion.div>
      </div>
    </div>

    <!-- Suggestion chips (only when empty) -->
    <div
      v-if="!hasMessages && props.suggestions.length > 0"
      class="flex gap-2 px-2 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:[scrollbar-width:none] sm:flex-wrap"
    >
      <button
        v-for="(s, i) in props.suggestions"
        :key="i"
        type="button"
        class="group inline-flex h-7.5 shrink-0 items-center gap-1.5 rounded-full border border-white/10 bg-black/10 px-3.5 pr-1.5 text-[12.5px] whitespace-nowrap text-white/70 transition-colors hover:bg-black/20 hover:text-white/90"
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

    <!-- Input bar -->
    <div
      class="flex items-center justify-between rounded-4xl bg-[rgba(34,34,34,0.15)] px-2 py-1.75 text-[12.5px] text-white/70"
      @click="focusInput"
    >
      <motion.div
        layout-id="assistant-icon"
        :transition="{ type: 'spring', stiffness: 400, damping: 30 }"
        class="flex size-11 shrink-0 items-center justify-center rounded-full bg-custom-blue"
      >
        <Sparkles class="size-4 text-white" :stroke-width="2" />
      </motion.div>

      <Input
        ref="inputRef"
        v-model="inputValue"
        type="text"
        :placeholder="props.placeholder"
        :disabled="isStreaming"
        aria-label="Message the assistant"
        class="h-auto! flex-1 border-0! bg-transparent! p-0! text-center text-[16px] font-normal tracking-tight text-white placeholder:text-white focus-visible:ring-0! focus-visible:ring-offset-0! disabled:opacity-50"
        @focus="isFocused = true"
        @blur="isFocused = false"
        @keydown="onKeydown"
      />

      <button
        type="button"
        :disabled="!inputValue.trim() || isStreaming"
        class="flex size-11 shrink-0 items-center justify-center rounded-full border border-white/30 text-white transition-all hover:bg-white/20 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
        aria-label="Send message"
        @click.stop="onSubmitClick"
      >
        <svg
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

<style scoped>
.thinking-shimmer {
  font-size: 13.5px;
  font-weight: 400;
  letter-spacing: -0.01em;
  background: linear-gradient(
    90deg,
    rgba(255, 255, 255, 0.25) 0%,
    rgba(255, 255, 255, 0.25) 40%,
    rgba(255, 255, 255, 0.95) 50%,
    rgba(255, 255, 255, 0.25) 60%,
    rgba(255, 255, 255, 0.25) 100%
  );
  background-size: 200% 100%;
  background-clip: text;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: thinking-shimmer 2.4s linear infinite;
}

@keyframes thinking-shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

.assistant-markdown {
  overflow-wrap: anywhere;
  white-space: normal;
}

.assistant-markdown :deep(p) {
  margin: 0;
}

.assistant-markdown :deep(p + p) {
  margin-top: 0.5rem;
}

.assistant-markdown :deep(strong) {
  font-weight: 650;
  color: rgba(255, 255, 255, 0.98);
}

.assistant-markdown :deep(ul),
.assistant-markdown :deep(ol) {
  margin: 0.4rem 0;
  padding-left: 1.1rem;
}

.assistant-markdown :deep(ul) {
  list-style: disc;
}

.assistant-markdown :deep(ol) {
  list-style: decimal;
}

.assistant-markdown :deep(li) {
  margin: 0.18rem 0;
}

.assistant-markdown :deep(code) {
  border-radius: 0.375rem;
  background: rgba(0, 0, 0, 0.28);
  padding: 0.1rem 0.25rem;
  font-size: 0.88em;
}

.assistant-markdown :deep(pre) {
  margin: 0.5rem 0;
  overflow-x: auto;
  border-radius: 0.75rem;
  background: rgba(0, 0, 0, 0.32);
  padding: 0.75rem;
}

.assistant-markdown :deep(a) {
  color: rgba(147, 197, 253, 1);
  text-decoration: underline;
  text-underline-offset: 2px;
}
</style>
