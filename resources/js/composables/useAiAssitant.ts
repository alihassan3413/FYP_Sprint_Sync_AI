import { getSuggestions, type AIContext } from '@/lib/ai-suggestions';

// Module-level shared state — singleton
const suggestions = ref<string[]>([]);
const isHidden = ref(false);
const isStreaming = ref(false);

let hotkeyAttached = false;

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

/**
 * Wires the global ⌘K shortcut. Call once at the layout root.
 * Pressing ⌘K focuses the dock input.
 */
function bindHotkey(focusFn?: () => void) {
  if (hotkeyAttached) return;
  hotkeyAttached = true;

  const handler = (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      focusFn?.();
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
    suggestions,
    isHidden,
    isStreaming,

    // Mutations
    setSuggestions,
    clearSuggestions,
    hide,
    show,
    setStreaming,
    bindHotkey,
  };
}

/**
 * Page-level helper — sets suggestions for a context and clears on unmount.
 *
 * Usage in any page:
 *   useDockContext('dashboard');
 */
export function useDockContext(context: AIContext) {
  const dock = useAiAssistant();
  dock.setSuggestions(getSuggestions(context));

  // Reset suggestions when leaving the page so the next page starts clean
  onBeforeUnmount(() => {
    dock.clearSuggestions();
  });
}
