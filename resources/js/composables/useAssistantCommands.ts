import { searchCommands } from '@/lib/command-search';
import { getCsrfToken } from '@/lib/csrf';
import type { AssistantCommandData } from '@/types/generated';
import { computed, ref } from 'vue';

export type AssistantCommand = AssistantCommandData;

export interface CommandGroup {
    category: string;
    commands: AssistantCommand[];
}

const commands = ref<AssistantCommand[]>([]);
const loadedWorkspaceId = ref<number | null | undefined>(undefined);
const isLoading = ref(false);
const loadError = ref<string | null>(null);

let inFlight: Promise<void> | null = null;

async function fetchCommands(workspaceId: number | null, conversationId: number | null): Promise<void> {
    isLoading.value = true;
    loadError.value = null;

    const params = new URLSearchParams();
    if (workspaceId !== null) params.set('workspace_id', String(workspaceId));
    if (conversationId !== null) params.set('conversation_id', String(conversationId));

    try {
        const response = await fetch(`/assistant/commands?${params.toString()}`, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
        }

        const body = await response.json();

        commands.value = body.commands ?? [];
        loadedWorkspaceId.value = workspaceId;
    } catch (e) {
        console.warn('[assistant] could not load the command list:', e);
        loadError.value = 'Commands could not be loaded.';
        commands.value = [];
        loadedWorkspaceId.value = undefined;
    } finally {
        isLoading.value = false;
    }
}

export function useAssistantCommands() {
    /**
     * Loads once per workspace. The list is authorization-filtered server-side,
     * so it has to be refetched when the user moves to another workspace.
     */
    async function ensureLoaded(workspaceId: number | null, conversationId: number | null = null): Promise<void> {
        if (loadedWorkspaceId.value === workspaceId && commands.value.length > 0) {
            return;
        }

        if (inFlight) {
            return inFlight;
        }

        inFlight = fetchCommands(workspaceId, conversationId).finally(() => {
            inFlight = null;
        });

        return inFlight;
    }

    function search(query: string): AssistantCommand[] {
        return searchCommands(commands.value, query);
    }

    function groupByCategory(list: AssistantCommand[]): CommandGroup[] {
        const groups: CommandGroup[] = [];

        for (const command of list) {
            const existing = groups.find((group) => group.category === command.category);

            if (existing) {
                existing.commands.push(command);
            } else {
                groups.push({ category: command.category, commands: [command] });
            }
        }

        return groups;
    }

    return {
        commands: computed(() => commands.value),
        isLoading,
        loadError,
        ensureLoaded,
        search,
        groupByCategory,
    };
}
