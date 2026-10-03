<script lang="ts">
/* Back restores this page from history; refresh it so new invoices and status changes show. */
let restoredFromHistory = false;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => (restoredFromHistory = true));
}
</script>

<script setup lang="ts">
import { ChevronRight, Clock, ReceiptText, Repeat } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney, INVOICE_STATUS_STYLES, periodLabel, rowStatus } from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';
import type { InvoiceSummaryData } from '@/types/generated';

type CurrencyAmount = { currency: string; amount_minor: number };

defineProps<{
    needsYou: InvoiceSummaryData[];
    invoices: InvoiceSummaryData[];
    /** What issued invoices still owe, per currency (amounts in different currencies are never added together). */
    waitingToBePaid: CurrencyAmount[];
    /** Active payments received in the workspace's current month, per currency. */
    receivedThisMonth: CurrencyAmount[];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Invoices', href: workspaceRoute('workspace.invoices.index') }];

/** What the owner has to do next, in a few words. */
function nextStep(invoice: InvoiceSummaryData): string {
    if (invoice.status === 'needs_hours') {
        return `${invoice.lines_missing_hours} ${invoice.lines_missing_hours === 1 ? 'person needs' : 'people need'} hours`;
    }

    return `${formatMoney(invoice.total_minor, invoice.currency)} · ready for you to approve`;
}

onMounted(() => {
    if (restoredFromHistory) {
        restoredFromHistory = false;
        router.reload({ only: ['needsYou', 'invoices'] });
    }
});
</script>

<template>
    <Head title="Invoices" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Money" title="Invoices" description="What has been prepared, and what is waiting for you." />

            <div class="grid gap-3 sm:grid-cols-2" data-testid="kpis">
                <div
                    v-for="kpi in [
                        { key: 'waiting', label: 'Waiting to be paid', amounts: waitingToBePaid },
                        { key: 'received', label: 'Received this month', amounts: receivedThisMonth },
                    ]"
                    :key="kpi.key"
                    class="bg-card rounded-2xl border p-5"
                    :data-testid="`kpi-${kpi.key}`"
                >
                    <p class="text-muted-foreground text-sm">{{ kpi.label }}</p>
                    <p v-if="!kpi.amounts.length" class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ formatMoney(0, 'USD') }}</p>
                    <p v-for="amount in kpi.amounts" :key="amount.currency" class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                        {{ formatMoney(amount.amount_minor, amount.currency) }}
                    </p>
                </div>
            </div>

            <section v-if="needsYou.length" data-testid="needs-you">
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Needs you</h2>
                <div class="grid gap-3 md:grid-cols-2">
                    <Link
                        v-for="invoice in needsYou"
                        :key="invoice.public_id"
                        :href="workspaceRoute('workspace.invoices.show', { invoice: invoice.public_id })"
                        class="group bg-card hover:border-foreground/20 focus-visible:ring-ring flex items-center gap-4 rounded-2xl border p-4 transition outline-none hover:shadow-sm focus-visible:ring-2"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="invoice.status === 'needs_hours' ? 'bg-amber-500/10 text-amber-600' : 'bg-sky-500/10 text-sky-600'"
                        >
                            <Clock v-if="invoice.status === 'needs_hours'" class="size-5" />
                            <ReceiptText v-else class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ invoice.title }} · {{ periodLabel(invoice.period_start) }}</p>
                            <p class="text-muted-foreground truncate text-sm">{{ invoice.client_name }} · {{ nextStep(invoice) }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="INVOICE_STATUS_STYLES[invoice.status]">
                            {{ invoice.status_label }}
                        </span>
                    </Link>
                </div>
            </section>

            <section>
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">All invoices</h2>
                <div class="bg-card overflow-hidden rounded-2xl border">
                    <AppEmptyState
                        v-if="!invoices.length"
                        title="No invoices yet"
                        description="Invoices appear here when a recurring invoice is due. You can also prepare one now from a client's recurring invoice."
                    >
                        <template #icon>
                            <Repeat class="size-5" />
                        </template>
                        <template #actions>
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="workspaceRoute('workspace.clients.index')">Go to clients</Link>
                            </Button>
                        </template>
                    </AppEmptyState>

                    <ul v-else class="divide-y" data-testid="invoice-list">
                        <li v-for="invoice in invoices" :key="invoice.public_id">
                            <Link
                                :href="workspaceRoute('workspace.invoices.show', { invoice: invoice.public_id })"
                                class="group hover:bg-muted/40 focus-visible:ring-ring flex items-center gap-4 px-4 py-3.5 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset sm:px-5"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ invoice.title }}
                                        <span class="text-muted-foreground font-normal">· {{ periodLabel(invoice.period_start) }}</span>
                                    </p>
                                    <p class="text-muted-foreground truncate text-sm">
                                        {{ invoice.client_name }} ·
                                        <span class="tabular-nums">{{
                                            invoice.number ?? (invoice.status === 'approved' ? 'Scheduled to send' : 'Draft')
                                        }}</span>
                                        <template v-if="invoice.payment_status === 'partially_paid'">
                                            · {{ formatMoney(invoice.balance_due_minor, invoice.currency) }} remaining</template
                                        >
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3">
                                    <span class="text-sm font-medium tabular-nums">{{
                                        invoice.status === 'needs_hours' ? '—' : formatMoney(invoice.total_minor, invoice.currency)
                                    }}</span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="rowStatus(invoice).style">
                                        {{ rowStatus(invoice).label }}
                                    </span>
                                </div>
                                <ChevronRight
                                    class="text-muted-foreground/60 group-hover:text-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 sm:block"
                                />
                            </Link>
                        </li>
                    </ul>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
