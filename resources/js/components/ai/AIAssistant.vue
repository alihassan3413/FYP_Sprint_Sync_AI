<script setup lang="ts">
import { AnimatePresence } from 'motion-v';

interface Props {
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Ask your personal AI assistant',
});

const { state, suggestions, isHidden } = useAiAssistant();
</script>

<template>
    <Teleport to="body">
        <AnimatePresence mode="sync">
            <template v-if="!isHidden">
                <AssistantFab v-if="state === 'collapsed'" key="fab" />

                <AssistantDock v-else-if="state === 'dock'" key="dock" :suggestions="suggestions" :placeholder="props.placeholder" />

                <AssistantPanel v-else key="panel" :suggestions="suggestions" :placeholder="props.placeholder" />
            </template>
        </AnimatePresence>
    </Teleport>
</template>
