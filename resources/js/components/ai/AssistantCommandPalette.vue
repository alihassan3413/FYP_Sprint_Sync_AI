<script setup lang="ts">
import { Loader2, Lock, Search } from 'lucide-vue-next';

/**
 * Slash-command picker for the assistant input.
 *
 * The list is filtered server-side to the tools this user may actually run, so
 * a member never sees an action they would only be refused. Picking a command
 * writes a starting phrase into the chat input rather than running anything —
 * arguments are still gathered conversationally, and writes still stop for
 * confirmation.
 */

const props = defineProps<{
    query: string;
    workspaceId: number | null;
    conversationId: number | null;
}>();

const emit = defineEmits<{
    (e: 'select', command: AssistantCommand): void;
    (e: 'dismiss'): void;
}>();

const { isLoading, loadError, ensureLoaded, search, groupByCategory } = useAssistantCommands();

const activeIndex = ref(0);

const results = computed(() => search(props.query));
const groups = computed(() => groupByCategory(results.value));

const flatResults = computed(() => groups.value.flatMap((group) => group.commands));

function indexOf(command: AssistantCommand): number {
    return flatResults.value.findIndex((entry) => entry.name === command.name);
}

watch(
    () => props.query,
    () => {
        activeIndex.value = 0;
    },
);

watch(
    () => props.workspaceId,
    (workspaceId) => void ensureLoaded(workspaceId, props.conversationId),
    { immediate: true },
);

function move(delta: number) {
    const count = flatResults.value.length;

    if (count === 0) {
        return;
    }

    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function choose(command?: AssistantCommand) {
    const picked = command ?? flatResults.value[activeIndex.value];

    if (picked) {
        emit('select', picked);
    }
}

defineExpose({
    move,
    choose,
    hasResults: computed(() => flatResults.value.length > 0),
});
</script>

<template>
    <div
        class="absolute bottom-full left-0 z-20 mb-2 w-full overflow-hidden rounded-2xl border border-white/10 bg-[rgba(18,18,20,0.92)] shadow-[0_16px_48px_rgba(0,0,0,0.4)] backdrop-blur-xl"
        role="listbox"
        aria-label="Assistant commands"
    >
        <div class="flex items-center gap-2 border-b border-white/10 px-3.5 py-2.5">
            <Search class="size-3.5 shrink-0 text-white/40" :stroke-width="2.2" />
            <span class="truncate text-[12px] text-white/50">
                {{ props.query.trim() === '' ? 'Type to search actions…' : props.query }}
            </span>
            <Loader2 v-if="isLoading" class="ml-auto size-3.5 shrink-0 animate-spin text-white/40" />
        </div>

        <div class="max-h-[280px] overflow-y-auto overscroll-contain py-1.5 [scrollbar-width:thin]">
            <p v-if="loadError" class="px-3.5 py-4 text-[12px] text-white/50">
                {{ loadError }}
            </p>

            <p v-else-if="isLoading && flatResults.length === 0" class="px-3.5 py-4 text-[12px] text-white/50">Loading actions…</p>

            <p v-else-if="flatResults.length === 0" class="px-3.5 py-4 text-[12px] text-white/50">
                Nothing matches that. Press Escape and just ask in your own words.
            </p>

            <template v-else>
                <div v-for="group in groups" :key="group.category" class="mb-1 last:mb-0">
                    <p class="px-3.5 pt-1.5 pb-1 text-[9.5px] font-medium tracking-[0.12em] text-white/35 uppercase">
                        {{ group.category }}
                    </p>

                    <button
                        v-for="command in group.commands"
                        :key="command.name"
                        type="button"
                        role="option"
                        :aria-selected="indexOf(command) === activeIndex"
                        :class="[
                            'flex w-full items-start gap-2.5 px-3.5 py-2 text-left transition-colors',
                            indexOf(command) === activeIndex ? 'bg-white/10' : 'hover:bg-white/5',
                        ]"
                        @click.stop="choose(command)"
                        @mousemove="activeIndex = indexOf(command)"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="truncate text-[13px] font-medium text-white/90">{{ command.label }}</span>
                                <Lock
                                    v-if="command.requires_confirmation"
                                    class="size-2.5 shrink-0 text-white/35"
                                    :stroke-width="2.5"
                                    aria-label="Asks before it changes anything"
                                />
                            </div>
                            <p class="truncate text-[11.5px] text-white/45">{{ command.description }}</p>
                        </div>
                    </button>
                </div>
            </template>
        </div>

        <div class="flex items-center gap-3 border-t border-white/10 px-3.5 py-1.5 text-[10px] text-white/35">
            <span>↑↓ to move</span>
            <span>↵ to pick</span>
            <span>esc to close</span>
        </div>
    </div>
</template>
