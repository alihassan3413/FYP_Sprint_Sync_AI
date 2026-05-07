<script setup lang="ts">
/**
 * ComingNextCard — the dark "feature preview" card.
 *
 * Stands out visually because it's the only dark surface on the page.
 * Use this to telegraph upcoming features (AI sprint planning, integrations,
 * etc.) — it's an honest "this product is going somewhere" signal that's
 * also a great teacher/investor brag.
 */

import { ArrowRight, Sparkles } from 'lucide-vue-next';

interface Props {
  eyebrow?: string;
  title: string;
  description: string;
  ctaLabel?: string;
  ctaHref?: string;
}

withDefaults(defineProps<Props>(), {
  eyebrow: 'Coming next',
  ctaLabel: 'Join the waitlist',
});

defineEmits<{ (e: 'cta'): void }>();
</script>

<template>
  <div
    class="relative overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 p-5 text-zinc-100 shadow-sm dark:border-zinc-800"
  >
    <!-- Soft violet glow -->
    <div
      class="pointer-events-none absolute -right-8 -top-8 size-32 rounded-full bg-violet-500/20 blur-3xl"
      aria-hidden="true"
    />

    <div class="relative">
      <div class="flex items-center gap-1.5 text-violet-300">
        <Sparkles class="size-3.5" />
        <span class="text-[10.5px] font-medium uppercase tracking-[0.08em]">
          {{ eyebrow }}
        </span>
      </div>

      <h3 class="mt-2 text-[15px] font-semibold tracking-tight">
        {{ title }}
      </h3>

      <p class="mt-1.5 text-[12.5px] leading-relaxed text-zinc-400">
        {{ description }}
      </p>

      <button
        v-if="ctaLabel"
        type="button"
        class="mt-3.5 inline-flex h-7 items-center gap-1.5 rounded-md border border-white/15 bg-white/10 px-2.5 text-[11.5px] font-medium text-zinc-100 transition-colors hover:border-white/25 hover:bg-white/15"
        @click="$emit('cta')"
      >
        {{ ctaLabel }}
        <ArrowRight class="size-3" />
      </button>
    </div>
  </div>
</template>
