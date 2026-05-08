<script setup lang="ts">
import { motion } from 'motion-v';
import { Sparkles } from 'lucide-vue-next';

const { openDock, messages } = useAiAssistant();
</script>

<template>
  <motion.button
    type="button"
    layout-id="assistant-shell"
    :transition="{
      layout: { type: 'spring', stiffness: 360, damping: 34, mass: 0.9 },
      opacity: { duration: 0.2 },
    }"
    :initial="{ opacity: 0 }"
    :animate="{ opacity: 1 }"
    :exit="{ opacity: 0, transition: { duration: 0.15 } }"
    :while-hover="{ scale: 1.06 }"
    :while-tap="{ scale: 0.94 }"
    aria-label="Open AI assistant (⌘K)"
    class="group fixed bottom-6 right-6 z-50 grid size-14 place-items-center rounded-full bg-[#365AFF] outline-none shadow-[0_8px_24px_rgba(54,90,255,0.45),inset_0_1px_0_rgba(255,255,255,0.3)] hover:shadow-[0_12px_32px_rgba(54,90,255,0.6),inset_0_1px_0_rgba(255,255,255,0.4)] focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#365AFF] max-sm:bottom-4 max-sm:right-4"
    @click="openDock"
  >
    <span
      v-if="messages.length > 0"
      aria-hidden="true"
      class="absolute inset-0 animate-ping rounded-full bg-[#365AFF] opacity-25"
    />

    <motion.span
      layout-id="assistant-icon"
      :transition="{ type: 'spring', stiffness: 400, damping: 30 }"
      class="relative grid place-items-center"
    >
      <Sparkles class="size-5 text-white" :stroke-width="2.2" />
    </motion.span>

    <span
      class="pointer-events-none absolute -top-9 right-0 rounded-md bg-black/85 px-2 py-1 text-[10px] font-medium tracking-wide text-white opacity-0 backdrop-blur-sm transition-opacity duration-150 group-hover:opacity-100"
    >
      ⌘K
    </span>
  </motion.button>
</template>
