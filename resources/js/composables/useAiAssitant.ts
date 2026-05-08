import { ref } from 'vue';
import { getSuggestions, type AIContext } from '@/lib/ai-suggestions';

export type AssistantState = 'collapsed' | 'dock' | 'expanded';

export interface AssistantMessage {
  id: string;
  role: 'user' | 'assistant' | 'tool';
  content: string;
  timestamp: number;
  streaming?: boolean;
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

function collapse() {
  state.value = 'collapsed';
}
function openDock() {
  state.value = 'dock';
}
function expand() {
  state.value = 'expanded';
}

function setSuggestions(items: string[]) {
  suggestions.value = items;
}
function clearSuggestions() {
  suggestions.value = [];
}

function hide() {
  isHidden.value = true;
}
function show() {
  isHidden.value = false;
}

function setStreaming(value: boolean) {
  isStreaming.value = value;
}

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


async function submit(prompt: string) {
  const trimmed = prompt.trim();
  if (!trimmed || isStreaming.value) return;

  addUserMessage(trimmed);
  inputValue.value = '';

  state.value = 'expanded';

  isStreaming.value = true;
  const assistantMsg = addAssistantMessage('', true);

  try {
    const mockResponse = `Got it: "${trimmed}". Once the Laravel /api/assistant/chat endpoint is live, this will stream real responses. Tool calls (like creating a workspace) will appear as inline confirmation cards.`;
    for (const char of mockResponse) {
      await new Promise((r) => setTimeout(r, 10));
      appendToMessage(assistantMsg.id, char);
    }
  } finally {
    finishMessage(assistantMsg.id);
    isStreaming.value = false;
  }
}

export function useAiAssistant() {
  return {
    state,
    suggestions,
    isHidden,
    isStreaming,
    messages,
    inputValue,

    collapse,
    openDock,
    expand,

    setSuggestions,
    clearSuggestions,
    hide,
    show,
    setStreaming,

    submit,
    clearConversation,
  };
}

export function useDockContext(context: AIContext) {
  const dock = useAiAssistant();
  dock.setSuggestions(getSuggestions(context));
}
