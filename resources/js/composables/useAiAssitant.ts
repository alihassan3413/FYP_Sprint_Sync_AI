import { onBeforeUnmount, ref } from 'vue';
import { getSuggestions, type AIContext } from '@/lib/ai-suggestions';

/**
 * Three states:
 *   collapsed → just the FAB visible (bottom-right circle)
 *   dock      → input bar visible (your existing AIAssistant component)
 *   expanded  → full chat panel with conversation history
 */
export type AssistantState = 'collapsed' | 'dock' | 'expanded';

export interface AssistantMessage {
  id: string;
  role: 'user' | 'assistant' | 'tool';
  content: string;
  timestamp: number;
  /** True while the assistant message is still streaming in */
  streaming?: boolean;
  /** Tool call awaiting user confirmation before execution */
  pendingTool?: {
    name: string;
    args: Record<string, unknown>;
    description: string;
  };
}

// ---- Module-level singleton state ----
const state = ref<AssistantState>('collapsed');
const suggestions = ref<string[]>([]);
const isHidden = ref(false);
const isStreaming = ref(false);
const messages = ref<AssistantMessage[]>([]);
const inputValue = ref('');

let hotkeyAttached = false;

// ---- State transitions ----
function collapse() {
  state.value = 'collapsed';
}
function openDock() {
  state.value = 'dock';
}
function expand() {
  state.value = 'expanded';
}
function toggle() {
  // Cmd+K cycles forward, but if there are messages we jump straight to expanded
  if (state.value === 'collapsed') {
    state.value = messages.value.length > 0 ? 'expanded' : 'dock';
  } else if (state.value === 'dock') {
    state.value = 'expanded';
  } else {
    state.value = 'collapsed';
  }
}

// ---- Suggestions ----
function setSuggestions(items: string[]) {
  suggestions.value = items;
}
function clearSuggestions() {
  suggestions.value = [];
}

// ---- Visibility ----
function hide() {
  isHidden.value = true;
}
function show() {
  isHidden.value = false;
}

// ---- Streaming flag ----
function setStreaming(value: boolean) {
  isStreaming.value = value;
}

// ---- Conversation ----
function clearConversation() {
  messages.value = [];
  inputValue.value = '';
}

function addUserMessage(content: string): AssistantMessage {
  const msg: AssistantMessage = {
    id: crypto.randomUUID(),
    role: 'user',
    content,
    timestamp: Date.now(),
  };
  messages.value.push(msg);
  return msg;
}

function addAssistantMessage(content = '', streaming = true): AssistantMessage {
  const msg: AssistantMessage = {
    id: crypto.randomUUID(),
    role: 'assistant',
    content,
    timestamp: Date.now(),
    streaming,
  };
  messages.value.push(msg);
  return msg;
}

function appendToMessage(id: string, chunk: string) {
  const msg = messages.value.find((m) => m.id === id);
  if (msg) msg.content += chunk;
}

function finishMessage(id: string) {
  const msg = messages.value.find((m) => m.id === id);
  if (msg) msg.streaming = false;
}

/**
 * Submit a prompt. Auto-expands so the user sees the response.
 * Replace the mock streaming with your real SSE call to Laravel.
 */
async function submit(prompt: string) {
  const trimmed = prompt.trim();
  if (!trimmed || isStreaming.value) return;

  addUserMessage(trimmed);
  inputValue.value = '';

  // Always expand on submit — user needs to see the conversation
  state.value = 'expanded';

  isStreaming.value = true;
  const assistantMsg = addAssistantMessage('', true);

  try {
    // ---- MOCK STREAMING — replace this block with real SSE ----
    const mockResponse = `Got it: "${trimmed}". Once the Laravel /api/assistant/chat endpoint is live, this will stream real responses. Tool calls (like creating a workspace) will appear as inline confirmation cards.`;
    for (const char of mockResponse) {
      await new Promise((r) => setTimeout(r, 10));
      appendToMessage(assistantMsg.id, char);
    }
    // ---- END MOCK ----
  } finally {
    finishMessage(assistantMsg.id);
    isStreaming.value = false;
  }
}

/**
 * Global hotkeys:
 *   ⌘K / Ctrl+K → toggle assistant
 *   Esc         → expanded → dock (one step back)
 */
function bindHotkey(focusFn?: () => void) {
  if (hotkeyAttached) return;
  hotkeyAttached = true;

  const handler = (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (state.value === 'collapsed') {
        openDock();
        // Defer focus until after the dock mounts
        setTimeout(() => focusFn?.(), 50);
      } else {
        toggle();
      }
    } else if (e.key === 'Escape' && state.value === 'expanded') {
      e.preventDefault();
      openDock();
    }
  };

  window.addEventListener('keydown', handler);

  onBeforeUnmount(() => {
    window.removeEventListener('keydown', handler);
    hotkeyAttached = false;
  });
}

export function useAiAssistant() {
  return {
    // State
    state,
    suggestions,
    isHidden,
    isStreaming,
    messages,
    inputValue,

    // State transitions
    collapse,
    openDock,
    expand,
    toggle,

    // Mutations
    setSuggestions,
    clearSuggestions,
    hide,
    show,
    setStreaming,
    bindHotkey,

    // Conversation
    submit,
    clearConversation,
  };
}

/**
 * Page-level helper — sets suggestions for a context and clears on unmount.
 * Usage: useDockContext('dashboard');
 */
export function useDockContext(context: AIContext) {
  const dock = useAiAssistant();
  dock.setSuggestions(getSuggestions(context));

  onBeforeUnmount(() => {
    dock.clearSuggestions();
  });
}
