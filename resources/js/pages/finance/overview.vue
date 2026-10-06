<script setup lang="ts">
import { AlertTriangle, Loader2, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };
type Overview = {
    native: { currency: string; invoiced: string; received: string; expenses: string; invoice_result: string; cash_result: string }[];
    converted: {
        invoiced: string | null;
        received: string | null;
        expenses: string | null;
        invoice_result: string | null;
        cash_result: string | null;
        invoice_result_negative: boolean;
        cash_result_negative: boolean;
    };
    missing_rates: { from: string; to: string; earliest: string; records: number }[];
};

const props = defineProps<{
    period: { key: string; from: string; to: string };
    reportingCurrency: string;
    displayCurrency: string;
    currencies: Option[];
    overview: Overview;
    rates: { public_id: string; base: string; quote: string; rate: string; rate_date: string; entered_by: string | null }[];
    canManage: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Finance overview', href: workspaceRoute('workspace.finance.overview') }];

const NEEDED = 'Exchange rate needed';

const metrics = computed(() => [
    { key: 'invoiced', label: 'Invoiced', hint: 'Issued invoices, by issue date', value: props.overview.converted.invoiced },
    { key: 'received', label: 'Received', hint: 'Payments, by date received', value: props.overview.converted.received },
    { key: 'expenses', label: 'Expenses', hint: 'Not voided, by expense date', value: props.overview.converted.expenses },
]);

const results = computed(() => [
    {
        key: 'invoice',
        label: 'Invoice-based result',
        hint: 'Invoiced − expenses',
        value: props.overview.converted.invoice_result,
        negative: props.overview.converted.invoice_result_negative,
    },
    {
        key: 'cash',
        label: 'Cash result',
        hint: 'Received − expenses',
        value: props.overview.converted.cash_result,
        negative: props.overview.converted.cash_result_negative,
    },
]);

const display = ref(props.displayCurrency);
watch(display, (currency) =>
    router.get(
        window.location.pathname,
        { period: props.period.key, from: props.period.from, to: props.period.to, currency },
        { preserveScroll: true, preserveState: true },
    ),
);

function makeReporting() {
    router.put(workspaceRoute('workspace.finance.reporting-currency'), { currency: display.value }, { preserveScroll: true });
}

/* ---- Exchange rates ------------------------------------------------------ */

const isRateOpen = ref(false);
const rateForm = useForm({ base_currency: '', quote_currency: '', rate: '', rate_date: '' });

function openRate(from = '', to = props.displayCurrency, date = props.period.from) {
    rateForm.defaults({
        base_currency: from || props.currencies.find((c) => c.value !== to)?.value || '',
        quote_currency: to,
        rate: '',
        rate_date: date,
    });
    rateForm.reset();
    rateForm.clearErrors();
    isRateOpen.value = true;
}

function saveRate() {
    if (rateForm.processing) return;
    rateForm.post(workspaceRoute('workspace.finance.exchange-rates.store'), { preserveScroll: true, onSuccess: () => (isRateOpen.value = false) });
}
</script>

<template>
    <Head title="Finance overview" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Money" title="Overview" description="Operating result before team compensation." />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <FinancePeriodPicker :period="period" :query="{ currency: displayCurrency }" />
                <div class="flex items-center gap-2 text-sm">
                    <Label for="display-currency" class="text-muted-foreground">Show in</Label>
                    <select id="display-currency" v-model="display" class="bg-background h-8 rounded-md border px-2" data-testid="display-currency">
                        <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.value }}</option>
                    </select>
                    <Button v-if="canManage && displayCurrency !== reportingCurrency" size="sm" variant="ghost" @click="makeReporting"
                        >Make default</Button
                    >
                </div>
            </div>

            <div
                v-if="overview.missing_rates.length"
                class="flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm"
                data-testid="missing-rates"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                <div class="flex-1 space-y-1">
                    <p class="font-medium">{{ NEEDED }}</p>
                    <p v-for="missing in overview.missing_rates" :key="missing.from">
                        {{ missing.from }} → {{ missing.to }} for {{ missing.records }} record(s) from {{ missing.earliest }}.
                        <button
                            v-if="canManage"
                            type="button"
                            class="font-medium underline"
                            @click="openRate(missing.from, missing.to, missing.earliest)"
                        >
                            Enter rate
                        </button>
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3" data-testid="metrics">
                <div v-for="metric in metrics" :key="metric.key" class="bg-card rounded-2xl border p-5" :data-testid="`metric-${metric.key}`">
                    <p class="text-muted-foreground text-sm">{{ metric.label }}</p>
                    <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums" :class="!metric.value && 'text-muted-foreground text-base'">
                        {{ metric.value ?? NEEDED }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">{{ metric.hint }}</p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2" data-testid="results">
                <div v-for="result in results" :key="result.key" class="bg-card rounded-2xl border p-5" :data-testid="`result-${result.key}`">
                    <p class="text-muted-foreground text-sm">{{ result.label }}</p>
                    <p
                        class="mt-1 text-3xl font-semibold tracking-tight tabular-nums"
                        :class="[!result.value && 'text-muted-foreground text-base', result.negative && 'text-destructive']"
                    >
                        {{ result.value ?? NEEDED }}
                    </p>
                    <p class="text-muted-foreground mt-1 text-xs">{{ result.hint }} · Operating result before team compensation</p>
                </div>
            </div>
            <p class="text-muted-foreground -mt-3 text-xs">Team compensation is not included yet.</p>

            <section v-if="overview.native.length" data-testid="native-breakdown">
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">By original currency</h2>
                <div class="bg-card overflow-x-auto rounded-2xl border">
                    <table class="w-full text-sm whitespace-nowrap">
                        <thead class="text-muted-foreground text-left text-xs">
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium">Currency</th>
                                <th class="px-4 py-2 text-right font-medium">Invoiced</th>
                                <th class="px-4 py-2 text-right font-medium">Received</th>
                                <th class="px-4 py-2 text-right font-medium">Expenses</th>
                                <th class="px-4 py-2 text-right font-medium">Invoice-based</th>
                                <th class="px-4 py-2 text-right font-medium">Cash</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="row in overview.native" :key="row.currency">
                                <td class="px-4 py-2 font-medium">{{ row.currency }}</td>
                                <td class="px-4 py-2 text-right">{{ row.invoiced }}</td>
                                <td class="px-4 py-2 text-right">{{ row.received }}</td>
                                <td class="px-4 py-2 text-right">{{ row.expenses }}</td>
                                <td class="px-4 py-2 text-right">{{ row.invoice_result }}</td>
                                <td class="px-4 py-2 text-right">{{ row.cash_result }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section data-testid="exchange-rates">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.12em] uppercase">Exchange rates</h2>
                    <Button v-if="canManage" size="sm" variant="outline" class="gap-1.5" data-testid="add-rate" @click="openRate()">
                        <Plus class="size-3.5" />
                        Add rate
                    </Button>
                </div>
                <div class="bg-card overflow-hidden rounded-2xl border">
                    <p v-if="!rates.length" class="text-muted-foreground p-5 text-sm">
                        No rates yet. Amounts in other currencies are only combined once you enter a rate; nothing is guessed.
                    </p>
                    <ul v-else class="divide-y text-sm">
                        <li v-for="rate in rates" :key="rate.public_id" class="flex items-center justify-between gap-4 px-4 py-2.5 sm:px-5">
                            <span class="tabular-nums">1 {{ rate.base }} = {{ rate.rate }} {{ rate.quote }}</span>
                            <span class="text-muted-foreground text-xs"
                                >from {{ rate.rate_date }}<template v-if="rate.entered_by"> · {{ rate.entered_by }}</template></span
                            >
                        </li>
                    </ul>
                </div>
                <p class="text-muted-foreground mt-2 text-xs">
                    Each record uses the latest rate on or before its own date. Once a total has been converted, it keeps that rate.
                </p>
            </section>
        </div>
    </AppLayout>

    <AppModal :open="isRateOpen" title="Add exchange rate" size="sm" @update:open="(value) => !rateForm.processing && (isRateOpen = value)">
        <form id="rate-form" class="space-y-4 pt-1" @submit.prevent="saveRate">
            <div class="flex items-end gap-2 text-sm">
                <span class="pb-2.5">1</span>
                <select v-model="rateForm.base_currency" class="bg-background h-10 rounded-md border px-2" aria-label="From currency">
                    <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.value }}</option>
                </select>
                <span class="pb-2.5">=</span>
                <input
                    v-model="rateForm.rate"
                    inputmode="decimal"
                    placeholder="280.50"
                    class="bg-background h-10 w-full rounded-md border px-3 tabular-nums"
                    aria-label="Rate"
                    data-testid="rate-input"
                />
                <select v-model="rateForm.quote_currency" class="bg-background h-10 rounded-md border px-2" aria-label="To currency">
                    <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.value }}</option>
                </select>
            </div>
            <InputError :message="rateForm.errors.rate || rateForm.errors.base_currency || rateForm.errors.quote_currency" />
            <div class="grid gap-1.5">
                <Label for="rate-date" class="text-sm font-medium">Applies from</Label>
                <input id="rate-date" v-model="rateForm.rate_date" type="date" class="bg-background h-10 rounded-md border px-2 text-sm" />
                <InputError :message="rateForm.errors.rate_date" />
            </div>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="rateForm.processing" @click="isRateOpen = false">Cancel</Button>
            <Button type="submit" form="rate-form" :disabled="rateForm.processing" data-testid="save-rate">
                <Loader2 v-if="rateForm.processing" class="mr-2 size-4 animate-spin" />
                Save rate
            </Button>
        </template>
    </AppModal>
</template>
