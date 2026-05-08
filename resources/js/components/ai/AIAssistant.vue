<script setup lang="ts">
import { AnimatePresence } from 'motion-v';

interface Props {
  suggestions?: string[];
  placeholder?: string;
  hidden?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  suggestions: () => [],
  placeholder: 'Ask your personal AI assistant',
  hidden: false,
});

const { state, isHidden, bindHotkey } = useAiAssistant();

bindHotkey();
</script>

<template>
  <Teleport to="body">
    <!--
      mode="sync" is critical here: it keeps both the leaving and entering
      element in the DOM simultaneously during the transition, which is
      what allows motion-v's layout-id morph to interpolate position/size
      symmetrically in both directions (open AND close).

      With mode="popLayout" or "wait", you get a clean open but the close
      becomes a separate fade because there's no destination element
      present to morph into.
    -->
    <AnimatePresence mode="sync">
      <template v-if="!props.hidden && !isHidden">
        <AssistantFab v-if="state === 'collapsed'" key="fab" />

        <AssistantDock
          v-else-if="state === 'dock'"
          key="dock"
          :suggestions="props.suggestions"
          :placeholder="props.placeholder"
        />

        <AssistantPanel
          v-else
          key="panel"
          :suggestions="props.suggestions"
          :placeholder="props.placeholder"
        />
      </template>
    </AnimatePresence>
  </Teleport>
</template>
