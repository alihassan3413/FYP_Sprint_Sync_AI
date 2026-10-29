<script lang="ts">
/* Back restores this page from history; refresh it so new invoices and status changes show. */
let restoredFromHistory = false;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => (restoredFromHistory = true));
}
</script>

<script setup lang="ts">
import { AlertTriangle, ChevronRight, Clock, MailWarning, Plus, ReceiptText, Repeat, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { deliveryNote, formatMoney, formatShortDate, periodLabel, rowStatus } from '@/lib/billing';
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

type AttentionKind = NonNullable<InvoiceSummaryData['attention']>['kind'];

/** Icon and colour for each kind of "needs you" item. */
const ATTENTION: Record<AttentionKind, { icon: typeof Clock; tone: string; badge: string }> = {
    needs_hours: { icon: Clock, tone: 'bg-amber-500/10 text-amber-600', badge: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    safety_review: { icon: AlertTriangle, tone: 'bg-orange-500/10 text-orange-600', badge: 'bg-orange-500/10 text-orange-700 dark:text-orange-400' },
    ready_to_send: { icon: Send, tone: 'bg-violet-500/10 text-violet-600', badge: 'bg-violet-500/10 text-violet-700 dark:text-violet-300' },
    ready_to_review: { icon: ReceiptText, tone: 'bg-sky-500/10 text-sky-600', badge: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' },
    email_failed: { icon: MailWarning, tone: 'bg-red-500/10 text-red-600', badge: 'bg-red-500/10 text-red-700 dark:text-red-400' },
};

const invoiceWhen = (invoice: InvoiceSummaryData) =>
    invoice.source === 'manual' && invoice.issue_date ? formatShortDate(invoice.issue_date) : periodLabel(invoice.period_start);

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
            <AppPageHeader eyebrow="Money" title="Invoices" description="What has been prepared, and what is waiting for you.">
                <template #actions>
                    <Button as-child size="sm" class="gap-1.5" data-testid="new-invoice">
                        <Link :href="workspaceRoute('workspace.invoices.create')"><Plus class="size-3.5" /> New invoice</Link>
                    </Button>
                </template>
            </AppPageHeader>

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
                        class="group bg-card hover:border-foreground/20 focus-visible:ring-ring flex items-start gap-4 rounded-2xl border p-4 transition outline-none hover:shadow-sm focus-visible:ring-2"
                        :data-testid="`needs-you-${invoice.attention!.kind}`"
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="ATTENTION[invoice.attention!.kind].tone">
                            <component :is="ATTENTION[invoice.attention!.kind].icon" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <p class="truncate font-semibold">{{ invoice.title }} · {{ invoiceWhen(invoice) }}</p>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="ATTENTION[invoice.attention!.kind].badge">
                                    {{ invoice.attention!.label }}
                                </span>
                            </div>
                            <p class="text-muted-foreground truncate text-sm">{{ invoice.client_name }}</p>
                            <p class="mt-1 text-sm">{{ invoice.attention!.reason }}</p>
                            <p class="text-foreground mt-2 inline-flex items-center gap-1 text-sm font-medium group-hover:underline">
                                {{ invoice.attention!.action }}
                                <ChevronRight class="size-3.5" />
                            </p>
                        </div>
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
                                        <span class="text-muted-foreground font-normal">· {{ invoiceWhen(invoice) }}</span>
                                    </p>
                                    <p class="text-muted-foreground truncate text-sm">
                                        {{ invoice.client_name }} ·
                                        <span class="tabular-nums">{{
                                            invoice.number ?? (invoice.status === 'approved' ? 'Scheduled to send' : 'Draft')
                                        }}</span>
                                        <template v-if="invoice.payment_status === 'partially_paid'">
                                            · {{ formatMoney(invoice.balance_due_minor, invoice.currency) }} remaining</template
                                        >
                                        <template v-if="deliveryNote(invoice)">
                                            ·
                                            <span :class="{ 'text-destructive': deliveryNote(invoice)!.isProblem }">{{
                                                deliveryNote(invoice)!.label
                                            }}</span></template
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
