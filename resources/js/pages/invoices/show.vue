<script setup lang="ts">
import { CheckCircle2, ChevronLeft, Clock, Loader2, Lock, ReceiptText, Send, Undo2, Zap } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import {
    basisPointsToInput,
    calculateTotals,
    formatMoney,
    formatShortDate,
    hoursToInput,
    INVOICE_STATUS_STYLES,
    lineAmount,
    minorToInput,
    monthName,
    parseScaled,
    periodLabel,
} from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';
import type { InvoiceData } from '@/types/generated';

const props = defineProps<{
    invoice: InvoiceData;
    canEdit: boolean;
    canApprove: boolean;
    /** The cancel window after approving (finance.approval_send_delay_minutes). */
    sendDelayMinutes: number;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const summary = computed(() => props.invoice.summary);
const isHourly = computed(() => summary.value.pricing_mode === 'hourly');
const editable = computed(() => props.invoice.is_editable && props.canEdit);
const money = (minor: number) => formatMoney(minor, summary.value.currency);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Invoices', href: workspaceRoute('workspace.invoices.index') },
    { title: `${summary.value.title} · ${periodLabel(summary.value.period_start)}`, href: '' },
]);

/* ---- Editing: hours (hourly) or amounts (fixed), saved automatically ---- */

const serverValue = (line: InvoiceData['lines'][number]) =>
    isHourly.value ? hoursToInput(line.quantity_centi) : minorToInput(line.unit_price_minor);

const values = reactive<Record<number, string>>({});
const editedSinceSend = new Set<number>();
/** Something was typed that hasn't been sent yet; approving waits for it. */
const unsent = ref(false);

function syncFromServer() {
    for (const line of props.invoice.lines) {
        if (!editedSinceSend.has(line.position)) values[line.position] = serverValue(line);
    }
}

syncFromServer();
watch(() => props.invoice, syncFromServer);

const errors = ref<Record<string, string>>({});
const saving = ref(false);
let saveAgain = false;
let timer: ReturnType<typeof setTimeout> | undefined;

function edited(position: number) {
    editedSinceSend.add(position);
    unsent.value = true;
    clearTimeout(timer);
    timer = setTimeout(save, 700);
}

/** Sends every value (they are absolute, so a repeat changes nothing). Overlapping saves queue up. */
function save() {
    clearTimeout(timer);

    if (!editable.value) return;

    if (saving.value) {
        saveAgain = true;

        return;
    }

    saving.value = true;
    unsent.value = false;
    editedSinceSend.clear();

    router.put(
        workspaceRoute('workspace.invoices.lines.update', { invoice: summary.value.public_id }),
        { lines: { ...values } },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['invoice', 'flash', 'errors'],
            onSuccess: () => (errors.value = {}),
            onError: (bag) => (errors.value = bag),
            onFinish: () => {
                saving.value = false;

                if (saveAgain) {
                    saveAgain = false;
                    save();
                }
            },
        },
    );
}

/* ---- Live preview; the server's numbers replace it after each save ---- */

const preview = computed(() => {
    const amounts = props.invoice.lines.map((line) => {
        const typed = parseScaled(values[line.position] ?? '');

        if (isHourly.value) return typed === null ? null : lineAmount(line.unit_price_minor, typed);

        return typed ?? line.unit_price_minor;
    });

    const totals = calculateTotals(
        amounts.map((amount) => amount ?? 0),
        props.invoice.adjustments.map((adjustment) => ({ kind: adjustment.kind, type: adjustment.type, value: adjustment.value })),
    );

    return { amounts, ...totals, missing: amounts.filter((amount) => amount === null).length };
});

const shown = computed(() =>
    editable.value
        ? preview.value
        : {
              amounts: props.invoice.lines.map((line) => line.amount_minor),
              subtotal: props.invoice.subtotal_minor,
              adjustments: props.invoice.adjustments.map((adjustment) => adjustment.amount_minor),
              total: summary.value.total_minor,
              missing: 0,
          },
);

/* ---- Approve -------------------------------------------------------------- */

const approving = ref(false);
const canApproveNow = computed(() => props.canApprove && summary.value.status === 'ready_to_review' && !saving.value && !unsent.value);

function approve() {
    if (!canApproveNow.value || approving.value) return;

    approving.value = true;
    router.post(
        workspaceRoute('workspace.invoices.approve', { invoice: summary.value.public_id }),
        { version: props.invoice.version },
        { preserveScroll: true, onFinish: () => (approving.value = false) },
    );
}

/* ---- Approved: scheduled, cancellable until it is issued -------------- */

const now = ref(Date.now());
const clock = setInterval(() => (now.value = Date.now()), 30_000);

/** "in 1 hour", "in 42 minutes", or null once the window has passed. */
const sendsIn = computed(() => {
    if (!props.invoice.send_after) return null;

    const remaining = (new Date(props.invoice.send_after).getTime() - now.value) / 60_000;

    if (remaining <= 0) return null;

    const minutes = Math.max(1, Math.round(remaining));
    if (minutes >= 60 && minutes % 60 === 0) return `in ${minutes / 60} hour${minutes === 60 ? '' : 's'}`;

    return `in ${minutes} minute${minutes === 1 ? '' : 's'}`;
});

const sendAtTime = computed(() =>
    props.invoice.send_after ? new Date(props.invoice.send_after).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '',
);

const cancelling = ref(false);

function cancelSending() {
    if (cancelling.value) return;

    cancelling.value = true;
    router.post(
        workspaceRoute('workspace.invoices.cancel-sending', { invoice: summary.value.public_id }),
        {},
        { preserveScroll: true, onFinish: () => (cancelling.value = false) },
    );
}

onBeforeUnmount(() => {
    clearInterval(clock);
    if (timer) save();
});
</script>

<template>
    <Head :title="`${summary.title} · ${periodLabel(summary.period_start)}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 pb-28 md:p-6 md:pb-28 lg:p-8 lg:pb-28">
            <Link
                :href="workspaceRoute('workspace.invoices.index')"
                class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm transition-colors"
            >
                <ChevronLeft class="size-3.5" />
                Invoices
            </Link>

            <!-- Header -->
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-muted-foreground text-sm">{{ summary.client_name }}</p>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ summary.title }} · {{ periodLabel(summary.period_start) }}</h1>
                    <p class="text-muted-foreground mt-1 text-sm tabular-nums">
                        <span v-if="summary.number" class="text-foreground font-medium" data-testid="invoice-number">{{ summary.number }}</span>
                        <span v-else>No invoice number yet · it gets one when it is sent</span>
                    </p>
                </div>
                <span class="rounded-full px-3 py-1 text-sm font-medium" :class="INVOICE_STATUS_STYLES[summary.status]" data-testid="invoice-status">
                    {{ summary.status_label }}
                </span>
            </header>

            <!-- What happens now -->
            <div
                v-if="summary.status === 'needs_hours'"
                class="flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
                data-testid="status-banner"
            >
                <Clock class="mt-0.5 size-4 shrink-0 text-amber-600" />
                <p>
                    Enter {{ monthName(summary.period_start) }}'s hours for each person. Leave a box empty if you don't know yet; type
                    <strong>0</strong> if they didn't work.
                    <span class="text-muted-foreground">{{ invoice.lines.length - shown.missing }} of {{ invoice.lines.length }} entered.</span>
                </p>
            </div>
            <div
                v-else-if="summary.status === 'ready_to_review'"
                class="flex items-start gap-3 rounded-2xl border border-sky-500/30 bg-sky-500/5 p-4 text-sm"
                data-testid="status-banner"
            >
                <component :is="summary.delivery_mode === 'auto_send' ? Zap : ReceiptText" class="mt-0.5 size-4 shrink-0 text-sky-600" />
                <p v-if="summary.delivery_mode === 'auto_send'">
                    Ready. This invoice is set to send automatically on {{ formatShortDate(summary.planned_send_on) }}. Automatic sending isn't
                    switched on yet, so approve it yourself for now.
                </p>
                <p v-else>
                    Ready to review. Check it, then approve. After you approve, you'll still have
                    {{
                        sendDelayMinutes % 60 === 0
                            ? `${sendDelayMinutes / 60} hour${sendDelayMinutes === 60 ? '' : 's'}`
                            : `${sendDelayMinutes} minutes`
                    }}
                    to cancel sending and make changes.
                </p>
            </div>
            <div
                v-else-if="summary.status === 'approved'"
                class="flex flex-col gap-4 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 sm:flex-row sm:items-center"
                data-testid="status-banner"
            >
                <CheckCircle2 class="hidden size-5 shrink-0 text-emerald-600 sm:block" />
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-semibold" data-testid="scheduled-text">
                        Approved<template v-if="sendsIn"> · Scheduled to send {{ sendsIn }} ({{ sendAtTime }})</template
                        ><template v-else> · Ready to send</template>
                    </p>
                    <p class="text-muted-foreground mt-0.5">You can cancel sending if you notice something that needs changing.</p>
                    <p class="text-muted-foreground mt-1 text-xs">Email sending will be enabled in a later phase, so it will wait here until then.</p>
                </div>
                <div v-if="canApprove" class="flex shrink-0 flex-wrap gap-2">
                    <Button
                        variant="outline"
                        class="bg-background gap-1.5"
                        :disabled="cancelling"
                        data-testid="cancel-sending"
                        @click="cancelSending"
                    >
                        <Loader2 v-if="cancelling" class="size-4 animate-spin" />
                        <Undo2 v-else class="size-4" />
                        Cancel sending
                    </Button>
                    <Button variant="ghost" class="gap-1.5" disabled title="Email sending arrives in a later phase" data-testid="send-now">
                        <Send class="size-4" />
                        Send now
                    </Button>
                </div>
            </div>
            <div
                v-else
                class="flex items-start gap-3 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm"
                data-testid="status-banner"
            >
                <Lock class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                <p>
                    Issued as <strong>{{ summary.number }}</strong>
                    <template v-if="invoice.issue_date"> on {{ formatShortDate(invoice.issue_date) }}</template
                    >.
                    <template v-if="invoice.due_date">Due {{ formatShortDate(invoice.due_date) }}.</template>
                    It can no longer be changed.
                </p>
            </div>

            <!-- The invoice -->
            <article class="bg-card rounded-2xl border p-5 shadow-xs sm:p-8">
                <div class="flex flex-col gap-6 border-b pb-6 sm:flex-row sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.14em] uppercase">Bill to</p>
                        <p class="mt-1 font-semibold">{{ invoice.bill_to.name }}</p>
                        <p class="text-muted-foreground text-sm break-words">{{ invoice.bill_to.billing_email }}</p>
                        <p v-if="invoice.bill_to.address" class="text-muted-foreground text-sm whitespace-pre-line">{{ invoice.bill_to.address }}</p>
                        <p v-if="invoice.bill_to.tax_id" class="text-muted-foreground text-sm">Tax ID {{ invoice.bill_to.tax_id }}</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:text-right">
                        <dt class="text-muted-foreground">Period</dt>
                        <dd>{{ formatShortDate(summary.period_start) }} – {{ formatShortDate(summary.period_end) }}</dd>
                        <template v-if="invoice.issue_date">
                            <dt class="text-muted-foreground">Issued</dt>
                            <dd>{{ formatShortDate(invoice.issue_date) }}</dd>
                            <dt class="text-muted-foreground">Due</dt>
                            <dd>{{ invoice.due_date ? formatShortDate(invoice.due_date) : '—' }}</dd>
                        </template>
                        <template v-else>
                            <dt class="text-muted-foreground">Prepared</dt>
                            <dd>{{ formatShortDate(invoice.generated_on) }}</dd>
                            <dt class="text-muted-foreground">Planned send</dt>
                            <dd>{{ formatShortDate(summary.planned_send_on) }}</dd>
                            <dt class="text-muted-foreground">Payment due</dt>
                            <dd>{{ invoice.due_in_days }} days after sending</dd>
                        </template>
                    </dl>
                </div>

                <!-- Lines -->
                <div
                    class="text-muted-foreground mt-5 hidden grid-cols-[minmax(0,1fr)_8rem_6rem_7rem] gap-4 text-[11px] font-semibold tracking-wider uppercase sm:grid"
                    :class="!isHourly && 'grid-cols-[minmax(0,1fr)_10rem]'"
                >
                    <span>{{ isHourly ? 'Team member' : 'Item' }}</span>
                    <template v-if="isHourly">
                        <span class="text-right">Hours</span>
                        <span class="text-right">Rate</span>
                    </template>
                    <span class="text-right">Amount</span>
                </div>

                <ul class="mt-2 divide-y divide-dashed" data-testid="invoice-lines">
                    <li
                        v-for="(line, index) in invoice.lines"
                        :key="line.position"
                        class="flex flex-col gap-2 py-3 sm:grid sm:items-center sm:gap-4"
                        :class="isHourly ? 'sm:grid-cols-[minmax(0,1fr)_8rem_6rem_7rem]' : 'sm:grid-cols-[minmax(0,1fr)_10rem]'"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ line.description }}</p>
                            <p v-if="line.role_label" class="text-muted-foreground truncate text-xs">{{ line.role_label }}</p>
                        </div>

                        <!-- Phones: one row of hours · rate · amount. Wider screens: the grid columns. -->
                        <div class="flex items-center gap-3 sm:contents">
                            <template v-if="isHourly">
                                <label
                                    v-if="editable"
                                    class="focus-within:border-ring focus-within:ring-ring/30 flex h-9 w-28 items-center rounded-md border px-2 focus-within:ring-2 sm:w-auto"
                                    :class="errors[`lines.${line.position}`] && 'border-destructive'"
                                >
                                    <input
                                        v-model="values[line.position]"
                                        class="w-full min-w-0 bg-transparent text-right tabular-nums outline-none"
                                        inputmode="decimal"
                                        :aria-label="`Hours for ${line.description}`"
                                        :data-testid="`hours-${index}`"
                                        @input="edited(line.position)"
                                        @blur="save"
                                        @keydown.enter.prevent="save"
                                    />
                                    <span class="text-muted-foreground pl-1 text-xs">h</span>
                                </label>
                                <p v-else class="text-sm tabular-nums sm:text-right">{{ hoursToInput(line.quantity_centi) }} h</p>
                                <p class="text-muted-foreground text-sm tabular-nums sm:text-right">× {{ money(line.unit_price_minor) }}</p>
                            </template>

                            <label
                                v-if="!isHourly && editable"
                                class="focus-within:border-ring focus-within:ring-ring/30 ml-auto flex h-9 w-40 items-center rounded-md border px-2 focus-within:ring-2 sm:ml-0 sm:w-auto"
                                :class="errors[`lines.${line.position}`] && 'border-destructive'"
                            >
                                <span class="text-muted-foreground text-sm">{{ summary.currency === 'USD' ? '$' : summary.currency }}</span>
                                <input
                                    v-model="values[line.position]"
                                    class="w-full min-w-0 bg-transparent text-right font-medium tabular-nums outline-none"
                                    inputmode="decimal"
                                    :aria-label="`Amount for ${line.description}`"
                                    :data-testid="`amount-${index}`"
                                    @input="edited(line.position)"
                                    @blur="save"
                                    @keydown.enter.prevent="save"
                                />
                            </label>
                            <p v-else class="ml-auto text-right font-medium tabular-nums sm:ml-0" :data-testid="`line-total-${index}`">
                                {{ shown.amounts[index] === null ? '—' : money(shown.amounts[index]!) }}
                            </p>
                        </div>

                        <p v-if="errors[`lines.${line.position}`]" class="text-destructive col-span-full text-xs">
                            {{ errors[`lines.${line.position}`] }}
                        </p>
                    </li>
                </ul>

                <!-- Totals -->
                <div class="mt-4 ml-auto w-full space-y-1.5 border-t pt-4 text-sm sm:w-80">
                    <div class="text-muted-foreground flex justify-between">
                        <span>Subtotal</span>
                        <span class="tabular-nums" data-testid="invoice-subtotal">{{ money(shown.subtotal) }}</span>
                    </div>
                    <div v-for="(adjustment, index) in invoice.adjustments" :key="index" class="text-muted-foreground flex justify-between gap-3">
                        <span
                            >{{ adjustment.label }}
                            <span v-if="adjustment.type === 'percentage'" class="text-xs">({{ basisPointsToInput(adjustment.value) }}%)</span></span
                        >
                        <span class="tabular-nums">{{ money(shown.adjustments[index] ?? 0) }}</span>
                    </div>
                    <div class="flex items-baseline justify-between border-t pt-2">
                        <span class="font-semibold">{{ shown.missing ? 'Total so far' : 'Total' }}</span>
                        <span class="text-2xl font-semibold tracking-tight tabular-nums" data-testid="invoice-total">{{ money(shown.total) }}</span>
                    </div>
                    <p v-if="editable" class="text-muted-foreground pt-1 text-xs">Changes here apply to this invoice only.</p>
                </div>
            </article>
        </div>

        <!-- Action bar -->
        <div v-if="canApprove && invoice.is_editable" class="bg-background/95 sticky bottom-0 z-10 border-t py-3 pr-24 pl-4 backdrop-blur sm:pl-6">
            <div class="flex items-center justify-between gap-3">
                <p class="text-muted-foreground text-xs" data-testid="save-state">
                    <template v-if="saving"><Loader2 class="mr-1 inline size-3.5 animate-spin align-[-2px]" />Saving…</template>
                    <template v-else-if="summary.status === 'needs_hours'">Approve becomes available once every person has hours.</template>
                    <template v-else><CheckCircle2 class="mr-1 inline size-3.5 align-[-2px] text-emerald-600" />All changes saved</template>
                </p>
                <Button :disabled="!canApproveNow || approving" data-testid="approve-invoice" @click="approve">
                    <Loader2 v-if="approving" class="mr-2 size-4 animate-spin" />
                    Approve
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
