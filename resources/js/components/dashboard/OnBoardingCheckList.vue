<script setup lang="ts">
/**
 * OnboardingChecklist — guides new workspaces through setup.
 *
 * Steps are computed from real workspace state, not stored. Each step has
 * a `done` predicate that runs against the props you pass in. The first
 * incomplete step gets the primary "Start" button; the rest are passive.
 */

import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';

export interface ChecklistStep {
  /** Stable key for tracking */
  key: string;
  title: string;
  description?: string;
  /** Whether this step is complete */
  done: boolean;
  /** Subtle subtext shown when complete (e.g. "9 members joined") */
  doneCaption?: string;
  /** href for the action button — Inertia link */
  href?: string;
  /** Estimated time (used for the summary line) */
  minutes?: number;
}

interface Props {
  steps: ChecklistStep[];
}

const props = defineProps<Props>();

const completedCount = computed(
  () => props.steps.filter((s) => s.done).length,
);

const totalCount = computed(() => props.steps.length);

const progressPct = computed(() =>
  totalCount.value > 0
    ? Math.round((completedCount.value / totalCount.value) * 100)
    : 0,
);

const remainingMinutes = computed(() =>
  props.steps
    .filter((s) => !s.done)
    .reduce((sum, s) => sum + (s.minutes ?? 0), 0),
);

const allDone = computed(() => completedCount.value === totalCount.value);

/** First incomplete step — gets the primary CTA */
const nextStepKey = computed(
  () => props.steps.find((s) => !s.done)?.key ?? null,
);
</script>

<template>
  <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
    <div class="px-5 pt-5 sm:px-6 sm:pt-6">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <h3 class="text-[15px] font-semibold tracking-tight">
            {{ allDone ? 'You\'re all set up' : 'Get SprintSync ready for your team' }}
          </h3>
          <p class="mt-1 text-xs text-muted-foreground">
            <template v-if="allDone">
              Every step complete · You're ready to ship.
            </template>
            <template v-else>
              <span class="tabular-nums">{{ completedCount }} of {{ totalCount }}</span> steps complete
              <template v-if="remainingMinutes > 0">
                · ~{{ remainingMinutes }} {{ remainingMinutes === 1 ? 'minute' : 'minutes' }} left
              </template>
            </template>
          </p>
        </div>
        <span
          class="text-xs font-medium tabular-nums"
          :class="allDone ? 'text-emerald-600 dark:text-emerald-400' : 'text-violet-600 dark:text-violet-400'"
        >
          {{ progressPct }}%
        </span>
      </div>

      <div class="mt-3 h-1 w-full overflow-hidden rounded-full bg-muted">
        <div
          class="h-full rounded-full transition-all duration-500"
          :class="allDone ? 'bg-emerald-500' : 'bg-violet-600 dark:bg-violet-400'"
          :style="{ width: `${progressPct}%` }"
        />
      </div>
    </div>

    <ul class="px-2 pb-2 pt-3 sm:px-3 sm:pb-3">
      <li
        v-for="step in steps"
        :key="step.key"
      >
        <div
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors"
          :class="
            !step.done && step.key === nextStepKey
              ? 'bg-amber-50/50 dark:bg-amber-500/[0.05]'
              : 'hover:bg-muted/40'
          "
        >
          <!-- Status indicator -->
          <span
            v-if="step.done"
            class="flex size-[18px] shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"
            aria-label="Complete"
          >
            <Check class="size-3" :stroke-width="3" />
          </span>
          <span
            v-else
            class="size-[18px] shrink-0 rounded-full border-[1.5px]"
            :class="
              step.key === nextStepKey
                ? 'border-amber-500 dark:border-amber-400'
                : 'border-muted-foreground/30'
            "
            aria-label="Incomplete"
          />

          <!-- Step content -->
          <div class="min-w-0 flex-1">
            <p
              class="text-[13px] font-medium leading-tight"
              :class="step.done && 'text-muted-foreground line-through'"
            >
              {{ step.title }}
            </p>
            <p
              v-if="step.done && step.doneCaption"
              class="mt-0.5 text-[11.5px] text-muted-foreground"
            >
              {{ step.doneCaption }}
            </p>
            <p
              v-else-if="step.description"
              class="mt-0.5 text-[11.5px] text-muted-foreground"
            >
              {{ step.description }}
            </p>
          </div>

          <!-- CTA — only on the next incomplete step -->
          <Button
            v-if="!step.done && step.key === nextStepKey && step.href"
            as-child
            size="sm"
            class="h-7 gap-1 px-2.5 text-xs"
          >
            <Link :href="step.href">
              Start
              <ArrowRight class="size-3" />
            </Link>
          </Button>
        </div>
      </li>
    </ul>
  </div>
</template>
