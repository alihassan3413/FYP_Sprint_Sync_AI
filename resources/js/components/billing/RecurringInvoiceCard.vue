<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Clock, Eye, FileText, Pause, Pencil, Play, Repeat, Zap } from 'lucide-vue-next';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import { basisPointsToInput, formatMoney, formatShortDate, monthName } from '@/lib/billing';
import type { BillingPlanData } from '@/types/generated';

const props = defineProps<{
    plan: BillingPlanData;
    canManage: boolean;
}>();

const emit = defineEmits<{ edit: []; pause: []; resume: []; prepare: [] }>();

const { workspaceRoute } = useCurrentWorkspace();

/* The invoice for the latest month that is due: open it if it exists, otherwise offer to prepare it. */
const dueMonth = computed(() => monthName(props.plan.due_period_start));

const isHourly = computed(() => props.plan.pricing_mode === 'hourly');
const money = (minor: number) => formatMoney(minor, props.plan.currency);

/** "6 items + Deel fee 2%" for fixed; "5 team members" for hourly. */
const summary = computed(() => {
    const count = props.plan.lines.length;
    const what = isHourly.value ? `${count} team member${count === 1 ? '' : 's'}` : `${count} item${count === 1 ? '' : 's'}`;
    const adjustments = props.plan.adjustments.map(
        (adjustment) =>
            `${adjustment.label} ${adjustment.type === 'percentage' ? `${basisPointsToInput(adjustment.value)}%` : money(adjustment.value)}`,
    );

    return [what, ...adjustments].join(' + ');
});

const headline = computed(() => {
    if (!isHourly.value) return `${money(props.plan.total_minor ?? 0)}/month`;

    const rates = props.plan.lines.map((line) => line.unit_price_minor);
    const low = Math.min(...rates);
    const high = Math.max(...rates);

    return low === high ? `${money(low)}/hr` : `${money(low)}–${money(high)}/hr`;
});

const delivery = computed(() => {
    if (props.plan.delivery_mode === 'auto_send') return { icon: Zap, text: 'Sends automatically when everything looks right' };

    if (isHourly.value) return { icon: Eye, text: "You review first · we'll ask on the 1st" };

    const days = props.plan.reminder_days_before ?? 3;

    return { icon: Eye, text: `You review first · reminder ${days === 0 ? 'on the day' : `${days} day${days === 1 ? '' : 's'} before`}` };
});

const actions = computed<DropdownEntry[]>(() => [
    { label: 'Edit', icon: Pencil, onSelect: () => emit('edit') },
    props.plan.paused
        ? { label: 'Resume', icon: Play, onSelect: () => emit('resume') }
        : { label: 'Pause', icon: Pause, onSelect: () => emit('pause') },
]);
</script>

<template>
    <article
        class="group bg-card hover:border-foreground/20 relative flex flex-col rounded-2xl border p-5 transition-all hover:shadow-sm"
        :class="plan.paused && 'opacity-75'"
        :data-testid="`plan-card-${plan.public_id}`"
    >
        <div class="flex items-start justify-between gap-3">
            <button
                type="button"
                class="focus-visible:ring-ring min-w-0 flex-1 rounded-md text-left outline-none after:absolute after:inset-0 after:rounded-2xl focus-visible:ring-2"
                :disabled="!canManage"
                :aria-label="`Edit ${plan.name}`"
                @click="emit('edit')"
            >
                <p class="truncate font-semibold">{{ plan.name }}</p>
                <p class="text-muted-foreground text-sm">{{ isHourly ? 'Hourly' : 'Fixed monthly' }}</p>
            </button>
            <div class="relative z-10 flex shrink-0 items-center gap-1.5">
                <span v-if="plan.paused" class="rounded-md bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                    >Paused</span
                >
                <span
                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium"
                    :class="isHourly ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300' : 'bg-muted text-muted-foreground'"
                >
                    <component :is="isHourly ? Clock : Repeat" class="size-3.5" />
                    {{ isHourly ? 'Hourly' : 'Fixed' }}
                </span>
                <AppDropDown v-if="canManage" :items="actions" :heading="plan.name" align="end" trigger-label="Open recurring invoice actions" />
            </div>
        </div>

        <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums" data-testid="plan-headline">{{ headline }}</p>
        <p class="text-muted-foreground mt-0.5 truncate text-sm">{{ summary }}</p>

        <div class="text-muted-foreground mt-4 flex flex-col gap-1.5 border-t pt-3 text-xs">
            <p class="inline-flex items-center gap-1.5">
                <CalendarDays class="size-3.5" />
                <template v-if="plan.paused">Paused · no invoices until you resume it</template>
                <template v-else-if="isHourly">
                    Next: prepared {{ formatShortDate(plan.next_generation_on) }}, sends {{ formatShortDate(plan.next_send_on) }}, for
                    {{ monthName(plan.next_period_start) }}
                </template>
                <template v-else>Next: {{ formatShortDate(plan.next_send_on) }}, for {{ monthName(plan.next_period_start) }}</template>
            </p>
            <p class="inline-flex items-center gap-1.5">
                <component :is="delivery.icon" class="size-3.5" />
                {{ delivery.text }}
            </p>
        </div>

        <div v-if="canManage" class="relative z-10 mt-4">
            <Button v-if="plan.due_invoice_public_id" variant="outline" size="sm" class="gap-1.5" as-child>
                <Link :href="workspaceRoute('workspace.invoices.show', { invoice: plan.due_invoice_public_id })" data-testid="open-due-invoice">
                    <FileText class="size-3.5" />
                    Open {{ dueMonth }} invoice
                </Link>
            </Button>
            <Button v-else-if="!plan.paused" variant="outline" size="sm" class="gap-1.5" data-testid="prepare-invoice" @click="emit('prepare')">
                <FileText class="size-3.5" />
                Prepare {{ dueMonth }} invoice
            </Button>
        </div>
    </article>
</template>
