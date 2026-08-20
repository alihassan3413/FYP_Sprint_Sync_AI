import { getSuggestions, type AIContext } from '@/lib/ai-suggestions';
import { getCsrfToken } from '@/lib/csrf';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useVoiceOutput } from './useVoiceOutput';

export type AssistantState = 'collapsed' | 'dock' | 'expanded';

export interface PendingTool {
    messageId: number;
    toolCallId: string;
    name: string;
    args: Record<string, unknown>;
    /** Server-derived context the args alone cannot show, such as a resolved project name. */
    details: Record<string, string>;
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
    isLoading?: boolean;
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

const { speak, speakChunk, flushSpeech, stopSpeaking } = useVoiceOutput();

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
function collapse() {
    state.value = 'collapsed';
}
function openDock() {
    state.value = 'dock';
}
function expand() {
    state.value = 'expanded';
}

// ---- Suggestions ----
function setSuggestions(items: string[]) {
    suggestions.value = items;
}
function clearSuggestions() {
    suggestions.value = [];
}

// ---- Page context ----
function setPageContext(ctx: PageContext) {
    pageContext.value = ctx;
}

// ---- Visibility ----
function hide() {
    isHidden.value = true;
}
function show() {
    isHidden.value = false;
}

// ---- Conversation ----
function clearConversation() {
    stopSpeaking();
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

function addAssistantMessage(content = '', streaming = true, isLoading = false): AssistantMessage {
    const msg: AssistantMessage = {
        id: crypto.randomUUID(),
        serverId: null,
        role: 'assistant',
        content,
        timestamp: Date.now(),
        streaming,
        isLoading,
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
        case 'invite_user': {
            const roleLabels = [args.custom_role, args.role && args.role !== 'member' ? args.role : null].filter(Boolean);

            return `Invite ${args.email ?? 'someone'}${roleLabels.length > 0 ? ` as ${roleLabels.join(' · ')}` : ''}`;
        }
        case 'create_task': {
            const person = args.assignee ?? args.assignee_email;

            return `Create task "${args.title ?? 'untitled'}"${person ? ` for ${person}` : ''}`;
        }
        case 'delete_task':
            return 'Delete this task permanently';
        case 'comment_on_task':
            return 'Post this comment on the task';
        case 'update_task': {
            const parts: string[] = [];

            if (args.assignee) parts.push(args.assignee === 'unassigned' ? 'unassign it' : `assign to ${args.assignee}`);
            if (args.column) parts.push(`move to ${args.column}`);
            if (args.sprint) parts.push(`sprint: ${args.sprint}`);
            if (args.due_date) parts.push(args.due_date === 'none' ? 'clear the due date' : `due ${args.due_date}`);
            if (args.title) parts.push(`rename to "${args.title}"`);

            return `Update task — ${parts.length > 0 ? parts.join(', ') : 'edit details'}`;
        }
        case 'create_project':
            return `Create project "${args.name ?? 'untitled'}"`;
        case 'manage_sprint': {
            const sprintAction = String(args.action ?? 'create');

            if (sprintAction === 'create') return `Plan sprint "${args.name ?? 'untitled'}"`;
            if (sprintAction === 'start') return 'Start this sprint';

            return `Complete this sprint${args.carry_over === 'next_sprint' ? ', carrying work to the next one' : ''}`;
        }
        case 'schedule_meeting': {
            const invitees = Array.isArray(args.participant_emails) ? args.participant_emails.length : 0;

            return `Schedule "${args.title ?? 'untitled'}" and invite ${invitees} ${invitees === 1 ? 'person' : 'people'}`;
        }
        // Add more as you build tools.
        default:
            return name.replace(/_/g, ' ');
    }
}

function getToolIntro(name: string): string {
    switch (name) {
        case 'invite_user':
            return 'I can send this invitation. Please confirm before I continue.';

        case 'create_workspace':
            return 'I can create this workspace for you. Please confirm first.';

        case 'create_task':
            return 'I can add this task to the project. Please confirm the details first.';

        case 'comment_on_task':
            return 'I can post this comment under your name, where everyone on the task will see it. Please check the wording first.';

        case 'create_project':
            return 'I can create this project for you. Please confirm first.';

        case 'schedule_meeting':
            return 'I can schedule this meeting and email everyone below. Please check the details and recipients first.';

        case 'manage_sprint':
            return 'I can make this change to the sprint. Completing one freezes its numbers, so please check first.';

        case 'update_task':
            return 'I can make this change to the task. Please check it is the right one first.';

        case 'delete_task':
            return 'I can delete this task. It cannot be undone, so please check it is the right one.';

        default:
            return 'I’m ready to perform this action. Please confirm.';
    }
}

async function* parseSSE(response: Response): AsyncGenerator<Record<string, unknown>> {
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

                const dataLine = rawEvent.split('\n').find((line) => line.startsWith('data: '));

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

async function consumeSseStream(response: Response, assistantMsg: AssistantMessage): Promise<void> {
    if (!response.ok) {
        let errorMsg = `Request failed (${response.status})`;
        try {
            const errBody = await response.json();
            errorMsg = errBody.message ?? errorMsg;
        } catch {
            /* ignore */
        }
        appendToMessage(assistantMsg.id, `\n\n⚠️ ${errorMsg}`);
        finishMessage(assistantMsg.id);
        return;
    }

    // Tracks pending tools that were superseded this turn so a later
    // tool_pending event with replaces_message_id can update the original
    // bubble in place instead of pushing a duplicate card.
    // key = old server-side message_id, value = host frontend message id
    const supersededHosts = new Map<number, string>();

    for await (const event of parseSSE(response)) {
        const type = event.type as string;

        switch (type) {
            case 'connected':
                conversationId.value = event.conversation_id as number;
                console.debug('[assistant] conversation_id set to', conversationId.value);
                break;

            case 'user_message_saved': {
                const userMsg = [...messages.value].reverse().find((m) => m.role === 'user' && m.serverId === null);
                if (userMsg) userMsg.serverId = event.id as number;
                break;
            }

            case 'text': {
                const msg = messages.value.find((m) => m.id === assistantMsg.id);
                if (msg) {
                    msg.isLoading = false;
                }
                appendToMessage(assistantMsg.id, event.delta as string);
                // Speaks whole sentences as they arrive; no-ops when the
                // speaker toggle is off.
                speakChunk(event.delta as string);
                break;
            }

            case 'tool_superseded': {
                const oldMessageId = event.message_id as number;
                const host = messages.value.find((m) => m.pendingTool?.messageId === oldMessageId);
                if (host) {
                    supersededHosts.set(oldMessageId, host.id);
                    host.pendingTool = undefined;
                }
                break;
            }

            case 'tool_pending': {
                const toolName = event.name as string;
                const args = (event.args as Record<string, unknown>) ?? {};
                const replacesId = event.replaces_message_id as number | undefined;

                const newPending: PendingTool = {
                    messageId: event.message_id as number,
                    toolCallId: event.tool_call_id as string,
                    name: toolName,
                    args,
                    details: (event.details as Record<string, string>) ?? {},
                    summary: summarizeTool(toolName, args),
                };

                const hostId = replacesId !== undefined ? supersededHosts.get(replacesId) : undefined;
                const host = hostId ? messages.value.find((m) => m.id === hostId) : undefined;

                if (host) {
                    // In-place update of the existing card.
                    host.pendingTool = newPending;
                    host.toolStatus = undefined;
                    supersededHosts.delete(replacesId!);

                    const current = messages.value.find((m) => m.id === assistantMsg.id);
                    if (current) {
                        current.isLoading = false;
                        if (!current.content.trim()) {
                            current.content = 'Updated the pending action. Please confirm when ready.';
                        }
                    }
                } else {
                    const msg = messages.value.find((m) => m.id === assistantMsg.id);
                    if (msg) {
                        msg.isLoading = false;
                        if (!msg.content.trim()) {
                            msg.content = getToolIntro(toolName);
                            // Written client-side, so it never reaches
                            // speakChunk through the text stream.
                            speak(msg.content);
                        }
                        msg.pendingTool = newPending;
                    }
                }
                break;
            }

            case 'tool_executed': {
                const result = event.result as { success?: boolean; switch_to?: string; error?: string } | undefined;

                // If the tool failed, surface the error to the user.
                if (result && result.success === false) {
                    appendToMessage(assistantMsg.id, `\n\n⚠️ ${result.error ?? 'Action failed.'}`);
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
                appendToMessage(assistantMsg.id, `\n\n⚠️ The action could not be completed.`);
                break;

            case 'tool_rejected': {
                const msg = messages.value.find((m) => m.pendingTool?.messageId === event.message_id);
                if (msg) {
                    msg.toolStatus = 'rejected';
                    msg.pendingTool = undefined;
                }
                break;
            }

            case 'awaiting_confirmation':
                finishMessage(assistantMsg.id);
                break;

            case 'error': {
                const msg = messages.value.find((m) => m.id === assistantMsg.id);
                const friendly = (event.message as string) ?? 'Something went wrong.';
                if (msg) {
                    msg.isLoading = false;
                    if (!msg.content.trim()) {
                        msg.content = `⚠️ ${friendly}`;
                    } else {
                        msg.content += `\n\n⚠️ ${friendly}`;
                    }
                }
                break;
            }

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

    // Interrupt the previous answer the moment a new one is asked.
    stopSpeaking();

    addUserMessage(trimmed);
    inputValue.value = '';
    state.value = 'expanded';

    isStreaming.value = true;
    const assistantMsg = addAssistantMessage('', true, true);

    try {
        const response = await fetch('/assistant/chat', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-XSRF-TOKEN': getCsrfToken(),
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
        appendToMessage(assistantMsg.id, '\n\n⚠️ Connection error. Please check your network and try again.');
    } finally {
        const msg = messages.value.find((m) => m.id === assistantMsg.id);

        if (msg) {
            msg.isLoading = false;

            if (!msg.content.trim() && !msg.pendingTool) {
                msg.content = 'I could not generate a response. Please try again.';
            }
        }

        finishMessage(assistantMsg.id);
        flushSpeech();
        isStreaming.value = false;
    }
}

async function confirmTool(messageId: number, action: 'confirm' | 'reject'): Promise<void> {
    if (isStreaming.value) return;

    stopSpeaking();

    const sourceMsg = messages.value.find((m) => m.pendingTool?.messageId === messageId);

    if (sourceMsg) {
        sourceMsg.toolStatus = action === 'confirm' ? 'executed' : 'rejected';
        sourceMsg.pendingTool = undefined;
        sourceMsg.isLoading = false;
    }

    if (action === 'reject') {
        const text = 'Okay, I canceled that action.';
        addAssistantMessage(text, false, false);
        speak(text);
        return;
    }

    isStreaming.value = true;
    const assistantMsg = addAssistantMessage('', true, true);

    try {
        const response = await fetch('/assistant/confirm', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-XSRF-TOKEN': getCsrfToken(),
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
        appendToMessage(assistantMsg.id, '\n\n⚠️ Connection error during action. Please try again.');
    } finally {
        const msg = messages.value.find((m) => m.id === assistantMsg.id);

        if (msg) {
            msg.isLoading = false;
        }

        finishMessage(assistantMsg.id);
        flushSpeech();
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
