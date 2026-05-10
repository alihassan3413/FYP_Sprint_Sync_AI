import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { getSuggestions, type AIContext } from '@/lib/ai-suggestions';
import { usePage } from '@inertiajs/vue3';
import type { SharedData } from '@/types';

export type AssistantState = 'collapsed' | 'dock' | 'expanded';

export interface PendingTool {
    messageId: number;
    toolCallId: string;
    name: string;
    args: Record<string, unknown>;
    /** Short human-readable summary shown to user. Built locally from name+args. */
    summary: string;
}

export interface AssistantMessage {
    id: string;
    serverId: number | null;
    role: 'user' | 'assistant' | 'tool';
    content: string;
    timestamp: number;
    streaming?: boolean;
    pendingTool?: PendingTool;
    toolStatus?: 'executed' | 'failed' | 'rejected';
}

type PageContext = {
    page?: string;
    route?: string;
    workspace_id?: number | null;
    workspace_slug?: string | null;
    workspace_name?: string | null;
};

// ---- Module-level singleton state ----
const state = ref<AssistantState>('collapsed');
const suggestions = ref<string[]>([]);
const isHidden = ref(false);
const isStreaming = ref(false);
const messages = ref<AssistantMessage[]>([]);
const inputValue = ref('');
const conversationId = ref<number | null>(null);
const pageContext = ref<{
    page?: string;
    route?: string;
    workspace_id?: number | null;
    workspace_slug?: string | null;
    workspace_name?: string | null;
}>({});

// ---- State transitions ----
function collapse() { state.value = 'collapsed'; }
function openDock() { state.value = 'dock'; }
function expand() { state.value = 'expanded'; }

// ---- Suggestions ----
function setSuggestions(items: string[]) { suggestions.value = items; }
function clearSuggestions() { suggestions.value = []; }

// ---- Page context ----
function setPageContext(ctx: PageContext) {
    pageContext.value = ctx;
}

// ---- Visibility ----
function hide() { isHidden.value = true; }
function show() { isHidden.value = false; }

// ---- Conversation ----
function clearConversation() {
    messages.value = [];
    inputValue.value = '';
    conversationId.value = null;
}

function addUserMessage(content: string): AssistantMessage {
    const msg: AssistantMessage = {
        id: crypto.randomUUID(),
        serverId: null,
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
        serverId: null,
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

// ---- CSRF helper ----
function getCsrfToken(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/**
 * Build a friendly summary line for a pending tool. The backend's tool
 * description is a PROMPT for the LLM, not user-facing copy. So we
 * compose our own based on tool name + args.
 *
 * Add cases here as you add more tools.
 */
function summarizeTool(name: string, args: Record<string, unknown>): string {
    switch (name) {
        case 'create_workspace':
            return `Create workspace "${args.name ?? 'untitled'}"`;
        case 'invite_user':
            return `Invite ${args.email ?? 'someone'}${args.role && args.role !== 'member' ? ` as ${args.role}` : ''}`;
        // Add more as you build tools.
        default:
            return name.replace(/_/g, ' ');
    }
}

async function* parseSSE(
    response: Response,
): AsyncGenerator<Record<string, unknown>> {
    if (!response.body) return;

    const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
    let buffer = '';

    try {
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += value;

            let boundary: number;
            while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                const rawEvent = buffer.slice(0, boundary);
                buffer = buffer.slice(boundary + 2);

                const dataLine = rawEvent
                    .split('\n')
                    .find((line) => line.startsWith('data: '));

                if (!dataLine) continue;

                const jsonStr = dataLine.slice(6).trim();
                if (!jsonStr) continue;

                try {
                    yield JSON.parse(jsonStr);
                } catch (e) {
                    console.warn('[assistant] Failed to parse SSE event:', jsonStr, e);
                }
            }
        }
    } finally {
        reader.releaseLock();
    }
}

async function consumeSseStream(
    response: Response,
    assistantMsg: AssistantMessage,
): Promise<void> {
    if (!response.ok) {
        let errorMsg = `Request failed (${response.status})`;
        try {
            const errBody = await response.json();
            errorMsg = errBody.message ?? errorMsg;
        } catch { /* ignore */ }
        appendToMessage(assistantMsg.id, `\n\n⚠️ ${errorMsg}`);
        finishMessage(assistantMsg.id);
        return;
    }

    for await (const event of parseSSE(response)) {
        const type = event.type as string;

        switch (type) {
            case 'connected':
                conversationId.value = event.conversation_id as number;
                console.debug('[assistant] conversation_id set to', conversationId.value);
                break;

            case 'user_message_saved': {
                const userMsg = [...messages.value]
                    .reverse()
                    .find((m) => m.role === 'user' && m.serverId === null);
                if (userMsg) userMsg.serverId = event.id as number;
                break;
            }

            case 'text':
                appendToMessage(assistantMsg.id, event.delta as string);
                break;

            case 'tool_pending': {
                const msg = messages.value.find((m) => m.id === assistantMsg.id);
                if (msg) {
                    const toolName = event.name as string;
                    const args = (event.args as Record<string, unknown>) ?? {};
                    msg.pendingTool = {
                        messageId: event.message_id as number,
                        toolCallId: event.tool_call_id as string,
                        name: toolName,
                        args,
                        summary: summarizeTool(toolName, args),
                    };
                }
                break;
            }

            case 'tool_executed': {
                const result = event.result as
                    | { success?: boolean; switch_to?: string; error?: string }
                    | undefined;

                // If the tool failed, surface the error to the user.
                if (result && result.success === false) {
                    appendToMessage(
                        assistantMsg.id,
                        `\n\n⚠️ ${result.error ?? 'Action failed.'}`,
                    );
                }

                // If the tool returned a switch_to URL, navigate via Inertia.
                // Defer slightly so the AI's confirmation message has time
                // to render before we navigate away.
                if (result?.switch_to) {
                    setTimeout(() => {
                        router.visit(result.switch_to!);
                    }, 1500);
                }

                console.debug('[assistant] tool executed:', event);
                break;
            }

            case 'tool_failed':
                appendToMessage(
                    assistantMsg.id,
                    `\n\n⚠️ The action could not be completed.`,
                );
                break;

            case 'tool_rejected': {
                const msg = messages.value.find(
                    (m) => m.pendingTool?.messageId === event.message_id,
                );
                if (msg) {
                    msg.toolStatus = 'rejected';
                    msg.pendingTool = undefined;
                }
                break;
            }

            case 'awaiting_confirmation':
                finishMessage(assistantMsg.id);
                break;

            case 'error':
                appendToMessage(
                    assistantMsg.id,
                    `\n\n⚠️ ${event.message as string}`,
                );
                break;

            case 'done':
            case 'stream_end':
                break;

            default:
                console.debug('[assistant] unknown event:', type, event);
        }
    }
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
        const response = await fetch('/api/assistant/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                message: trimmed,
                conversation_id: conversationId.value,
                page_context: pageContext.value,
            }),
        });

        await consumeSseStream(response, assistantMsg);
    } catch (e) {
        console.error('[assistant] submit error:', e);
        appendToMessage(
            assistantMsg.id,
            '\n\n⚠️ Connection error. Please check your network and try again.',
        );
    } finally {
        finishMessage(assistantMsg.id);
        isStreaming.value = false;
    }
}

async function confirmTool(
    messageId: number,
    action: 'confirm' | 'reject',
): Promise<void> {
    if (isStreaming.value) return;

    const sourceMsg = messages.value.find(
        (m) => m.pendingTool?.messageId === messageId,
    );
    if (sourceMsg) {
        sourceMsg.toolStatus = action === 'confirm' ? 'executed' : 'rejected';
        sourceMsg.pendingTool = undefined;
    }

    isStreaming.value = true;
    const assistantMsg = addAssistantMessage('', true);

    try {
        const response = await fetch('/api/assistant/confirm', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                message_id: messageId,
                action,
            }),
        });

        await consumeSseStream(response, assistantMsg);
    } catch (e) {
        console.error('[assistant] confirmTool error:', e);
        appendToMessage(
            assistantMsg.id,
            '\n\n⚠️ Connection error during action. Please try again.',
        );
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
        conversationId,
        pageContext,
        collapse,
        openDock,
        expand,
        setSuggestions,
        clearSuggestions,
        setPageContext,
        hide,
        show,
        submit,
        confirmTool,
        clearConversation,
    };
}

export function useDockContext(context: AIContext) {
    const dock = useAiAssistant();
    dock.setSuggestions(getSuggestions(context));
}


export function useAssistantPageContext(ctx: PageContext) {
    const dock = useAiAssistant();
    const page = usePage<SharedData>();

    const currentWorkspace = page.props.workspace?.current ?? null;

    dock.setPageContext({
        ...ctx,
        workspace_id: currentWorkspace?.id ?? null,
        workspace_slug: currentWorkspace?.slug ?? null,
        workspace_name: currentWorkspace?.name ?? null,
    });
}
