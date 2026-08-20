<script setup lang="ts">
/**
 * ProjectHealthCard — a verdict on one project, and who is carrying it.
 *
 * Everything shown is computed server-side by EvaluateProjectHealth. The card
 * ranks the findings and draws the workload split; it never decides anything.
 */
import { AlertTriangle, Info, TriangleAlert, Users } from 'lucide-vue-next';

import { type ProjectHealth, rankFindings, severityDot, severityStyles, shareTone, verdictStyles } from '@/lib/health';

const props = defineProps<{
    health: ProjectHealth;
    /** Hide the project name when the card already sits under one. */
    hideTitle?: boolean;
}>();

const findings = computed(() => rankFindings(props.health.signals));

/** Only people, and only those actually holding something. */
const workload = computed(() => props.health.workload.filter((entry) => entry.open_tasks > 0));

const hasWork = computed(() => props.health.total_tasks > 0);

const severityIcon = { critical: TriangleAlert, warning: AlertTriangle, note: Info } as const;
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm sm:p-6">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 v-if="!hideTitle" class="truncate text-[15px] font-semibold tracking-tight">
                    {{ health.project_name }}
                </h3>
                <p class="text-muted-foreground mt-0.5 text-[11.5px]">
                    {{ health.completed_tasks }} of {{ health.total_tasks }} done · {{ health.completion_percentage }}%
                    <span v-if="health.active_sprint_name"> · {{ health.active_sprint_name }}</span>
                </p>
            </div>

            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold whitespace-nowrap" :class="verdictStyles[health.verdict]">
                {{ health.verdict_label }}
            </span>
        </div>

        <template v-if="hasWork">
            <!-- completion -->
            <div class="mt-4">
                <div class="bg-muted h-1.5 overflow-hidden rounded-full">
                    <div
                        class="h-full rounded-full bg-lime-500 transition-all duration-700"
                        :style="{ width: health.completion_percentage + '%' }"
                    ></div>
                </div>
            </div>

            <!-- the numbers that drive the verdict -->
            <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="bg-muted/40 rounded-lg px-2 py-2">
                    <dt class="text-muted-foreground text-[10px] font-semibold tracking-wide uppercase">Open</dt>
                    <dd class="mt-0.5 text-[15px] font-bold tabular-nums">{{ health.open_tasks }}</dd>
                </div>
                <div class="bg-muted/40 rounded-lg px-2 py-2">
                    <dt class="text-muted-foreground text-[10px] font-semibold tracking-wide uppercase">Overdue</dt>
                    <dd class="mt-0.5 text-[15px] font-bold tabular-nums" :class="health.overdue_tasks > 0 ? 'text-rose-500' : ''">
                        {{ health.overdue_tasks }}
                    </dd>
                </div>
                <div class="bg-muted/40 rounded-lg px-2 py-2">
                    <dt class="text-muted-foreground text-[10px] font-semibold tracking-wide uppercase">Unowned</dt>
                    <dd class="mt-0.5 text-[15px] font-bold tabular-nums" :class="health.unassigned_open_tasks > 0 ? 'text-amber-500' : ''">
                        {{ health.unassigned_open_tasks }}
                    </dd>
                </div>
            </dl>

            <!-- who is carrying it -->
            <div v-if="workload.length > 0" class="mt-5">
                <p class="text-muted-foreground mb-2.5 flex items-center gap-1.5 text-[10.5px] font-semibold tracking-wide uppercase">
                    <Users class="size-3" /> Open work by person
                </p>

                <div class="space-y-2">
                    <div v-for="(entry, index) in workload" :key="entry.name" class="flex items-center gap-2.5">
                        <span class="w-20 shrink-0 truncate text-[12px] font-medium sm:w-24">{{ entry.name }}</span>

                        <div class="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                            <div
                                class="h-full rounded-full transition-all duration-700"
                                :class="shareTone(entry, index === 0)"
                                :style="{ width: Math.max(entry.share_percentage, 4) + '%' }"
                            ></div>
                        </div>

                        <span class="text-muted-foreground w-14 shrink-0 text-right text-[11px] font-semibold tabular-nums">
                            {{ entry.open_tasks }} · {{ entry.share_percentage }}%
                        </span>
                    </div>
                </div>
            </div>

            <!-- findings -->
            <div v-if="findings.length > 0" class="mt-5 space-y-2">
                <div v-for="finding in findings" :key="finding.code" class="rounded-lg border p-3" :class="severityStyles[finding.severity]">
                    <div class="flex items-start gap-2">
                        <component
                            :is="severityIcon[finding.severity]"
                            class="mt-0.5 size-3.5 shrink-0"
                            :class="severityDot[finding.severity].replace('bg-', 'text-')"
                        />
                        <div class="min-w-0">
                            <p class="text-[12.5px] leading-snug font-semibold">{{ finding.headline }}</p>
                            <p class="text-muted-foreground mt-0.5 text-[11.5px] leading-relaxed">{{ finding.detail }}</p>
                            <p v-if="finding.suggestion" class="mt-1 text-[11.5px] leading-relaxed font-medium">
                                {{ finding.suggestion }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <p v-else class="text-muted-foreground mt-4 text-xs">No tasks yet, so there is nothing to judge.</p>
    </div>
</template>
