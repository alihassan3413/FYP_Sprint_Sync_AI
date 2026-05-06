<script setup lang="ts">
/**
 * AppAIInsight — the violet "AI is suggesting something" strip.
 *
 * Designed for use on any page where SprintSync's AI surfaces actionable
 * suggestions: workload balancing on Teams, sprint health on Sprints,
 * blocker detection on Tasks, etc.
 *
 * Slots:
 *   - default → main insight text (can include action buttons inline)
 *   - subject → optional subject visual on the left (avatar, avatar stack, icon)
 *   - actions → primary action buttons on the right
 *
 * Emits `dismiss` when the user closes the strip.
 */

import { Sparkles, X } from 'lucide-vue-next';

interface Props {
  title: string;
  /** Show the "Beta" pill next to the title */
  beta?: boolean;
  /** Hide the dismiss (X) button */
  notDismissible?: boolean;
}

withDefaults(defineProps<Props>(), {
  beta: false,
  notDismissible: false,
});

defineEmits<{ (e: 'dismiss'): void }>();
</script>

<template>
  <div
    class="relative flex items-start gap-3 overflow-hidden rounded-xl border bg-linear-to-br from-violet-50/60 via-card to-card p-4 dark:from-violet-500/[0.07] dark:via-card dark:to-card"
  >
    <!-- Left: subject (default to a Sparkles tile) -->
    <div class="shrink-0">
      <slot name="subject">
        <div
          class="flex size-8 items-center justify-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300"
        >
          <Sparkles class="size-4" />
        </div>
      </slot>
    </div>

    <!-- Middle: heading + body -->
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <Sparkles
          v-if="$slots.subject"
          class="size-3.5 text-violet-600 dark:text-violet-400"
        />
        <p class="text-sm font-medium">{{ title }}</p>
        <AppBadge v-if="beta" variant="purple" size="sm">Beta</AppBadge>
      </div>

      <div class="mt-0.5 text-sm text-muted-foreground">
        <slot />
      </div>
    </div>

    <!-- Right: actions + dismiss -->
    <div class="flex shrink-0 items-center gap-1">
      <slot name="actions" />

      <button
        v-if="!notDismissible"
        type="button"
        aria-label="Dismiss insight"
        class="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        @click="$emit('dismiss')"
      >
        <X class="size-4" />
      </button>
    </div>
  </div>
</template>
