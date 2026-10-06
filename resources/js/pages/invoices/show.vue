<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    Clock,
    Download,
    Loader2,
    Lock,
    Mail,
    ReceiptText,
    RefreshCw,
    Send,
    Undo2,
    Zap,
} from 'lucide-vue-next';

import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

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
    canManageInvoicing: boolean;
    /** Whether Settings → Invoicing currently has everything an invoice needs. */
    hasCompleteInvoicingDetails: boolean;
    canRecordPayments: boolean;
    paymentMethods: { value: string; label: string }[];
    /** Today in the workspace's timezone (Y-m-d). */
    today: string;
    /** Send now and retry sending. */
    canSend: boolean;
    /** Whether Settings → Email delivery has been set up; invoices are only ever sent through it. */
    mailConnected: boolean;
    /** Time recorded for this period since the draft read it (drafts only). */
    trackedUpdate: { delta_seconds: number; lines: number } | null;
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

/* ---- Recorded (time tracking) vs billed hours: a reason when they differ ---- */

const reasons = reactive<Record<number, string>>({});

function syncReasons() {
    for (const line of props.invoice.lines) {
        if (!editedSinceSend.has(line.position)) reasons[line.position] = line.quantity_reason ?? '';
    }
}

syncReasons();
watch(() => props.invoice, syncReasons);

/** Billed hours (typed) differ from the hours time tracking recorded. */
function billedDiffers(line: InvoiceData['lines'][number]): boolean {
    if (line.tracked_quantity_centi === null) return false;

    const typed = parseScaled(values[line.position] ?? '');

    return typed !== null && typed !== line.tracked_quantity_centi;
}

const refreshing = ref(false);

function refreshHours() {
    if (refreshing.value) return;

    refreshing.value = true;
    router.post(
        workspaceRoute('workspace.invoices.refresh-hours', { invoice: summary.value.public_id }),
        {},
        { preserveScroll: true, onFinish: () => (refreshing.value = false) },
    );
}
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
    /* Typed values stay "being edited" until the server accepts them, so a refusal (a missing reason) never wipes them. */
    const sent = new Set(editedSinceSend);

    router.put(
        workspaceRoute('workspace.invoices.lines.update', { invoice: summary.value.public_id }),
        { lines: { ...values }, reasons: isHourly.value ? { ...reasons } : {} },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['invoice', 'flash', 'errors'],
            onSuccess: () => {
                errors.value = {};
                sent.forEach((position) => editedSinceSend.delete(position));
                syncFromServer();
                syncReasons();
            },
            onError: (bag) => {
                errors.value = bag;
                unsent.value = true;
            },
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
const canApproveNow = computed(
    () => props.canApprove && summary.value.status === 'ready_to_review' && !saving.value && !unsent.value && !senderBlocksApproval.value,
);

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

/* ---- Email delivery: separate from the invoice's own status ---------- */

const delivery = computed(() => props.invoice.delivery);
const sendNowOpen = ref(false);
const sendingNow = ref(false);

function sendNow() {
    if (sendingNow.value) return;

    sendingNow.value = true;
    router.post(
        workspaceRoute('workspace.invoices.send-now', { invoice: summary.value.public_id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                sendingNow.value = false;
                sendNowOpen.value = false;
            },
        },
    );
}

const retryOpen = ref(false);
const retrying = ref(false);

function retry() {
    if (retrying.value) return;

    retrying.value = true;
    router.post(
        workspaceRoute('workspace.invoices.delivery.retry', { invoice: summary.value.public_id }),
        { confirmed: delivery.value?.status === 'needs_attention' },
        {
            preserveScroll: true,
            onFinish: () => {
                retrying.value = false;
                retryOpen.value = false;
            },
        },
    );
}

/** While an email is on its way, check back until it settles. */
const inFlight = computed(() => delivery.value?.status === 'pending' || delivery.value?.status === 'sending');
const { start: startPolling, stop: stopPolling } = usePoll(4000, { only: ['invoice'] }, { autoStart: false });
watch(inFlight, (busy) => (busy ? startPolling() : stopPolling()), { immediate: true });

const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

/* ---- Sender (who the invoice is from) --------------------------------- */

const from = computed(() => props.invoice.bill_from);
const fromLines = computed(() => {
    const f = from.value;

    if (!f) return [];

    return [
        [f.address_line1, f.address_line2].filter(Boolean).join(', '),
        [f.city, f.region, f.postal_code].filter(Boolean).join(', '),
        f.country,
    ].filter(Boolean);
});

const refreshingSender = ref(false);

function useCurrentSender() {
    if (refreshingSender.value) return;

    refreshingSender.value = true;
    router.post(
        workspaceRoute('workspace.invoices.sender.refresh', { invoice: summary.value.public_id }),
        {},
        { preserveScroll: true, onFinish: () => (refreshingSender.value = false) },
    );
}

/** Before approval, the sender must be complete (or Settings must be, for an invoice prepared before them). */
const senderBlocksApproval = computed(
    () => props.invoice.is_editable && !props.invoice.sender_complete && !(props.invoice.bill_from === null && props.hasCompleteInvoicingDetails),
);

const delayLabel = computed(() =>
    props.sendDelayMinutes % 60 === 0
        ? `${props.sendDelayMinutes / 60} hour${props.sendDelayMinutes === 60 ? '' : 's'}`
        : `${props.sendDelayMinutes} minutes`,
);

const columnClass = computed(() => (isHourly.value ? 'sm:grid-cols-[minmax(0,1fr)_7.5rem_6rem_7.5rem]' : 'sm:grid-cols-[minmax(0,1fr)_10rem]'));

onBeforeUnmount(() => {
    clearInterval(clock);
    if (timer) save();
});
</script>

<template>
    <Head :title="`${summary.title} · ${periodLabel(summary.period_start)}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-5 p-4 pb-28 md:p-6 md:pb-28 lg:p-8 lg:pb-28">
            <Link
                :href="workspaceRoute('workspace.invoices.index')"
                class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm transition-colors"
            >
                <ChevronLeft class="size-3.5" />
                Invoices
            </Link>

            <!-- Workflow: status and actions, outside the document -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold tracking-tight">{{ summary.client_name }} · {{ summary.title }}</h1>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="INVOICE_STATUS_STYLES[summary.status]"
                            data-testid="invoice-status"
                        >
                            {{ summary.status_label }}
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1 text-sm" data-testid="status-line">
                        <template v-if="summary.status === 'needs_hours'">
                            <Clock class="mr-1 inline size-3.5 align-[-2px] text-amber-600" />
                            Enter {{ monthName(summary.period_start) }}'s hours. Leave a box empty if you don't know yet; type 0 if they didn't work.
                            {{ invoice.lines.length - shown.missing }} of {{ invoice.lines.length }} entered.
                        </template>
                        <template v-else-if="summary.status === 'ready_to_review'">
                            <component
                                :is="summary.delivery_mode === 'auto_send' ? Zap : ReceiptText"
                                class="mr-1 inline size-3.5 align-[-2px] text-sky-600"
                            />
                            <template v-if="invoice.sends_automatically">
                                Sends automatically on {{ formatShortDate(summary.planned_send_on) }} if everything looks right. You can review and
                                approve it sooner.
                            </template>
                            <template v-else
                                >Ready to review. After you approve, you have {{ delayLabel }} to cancel sending and make changes.</template
                            >
                        </template>
                        <template v-else-if="summary.status === 'approved'">
                            <CheckCircle2 class="mr-1 inline size-3.5 align-[-2px] text-violet-600" />
                            <span class="text-foreground font-medium" data-testid="scheduled-text"
                                >{{ invoice.approved_automatically ? 'Approved automatically by SprintSync' : 'Approved'
                                }}<template v-if="sendsIn"> · Scheduled to send {{ sendsIn }} ({{ sendAtTime }})</template
                                ><template v-else> · Ready to send</template></span
                            >. You can cancel sending if you notice something that needs changing.
                            <template v-if="!mailConnected"> Connect email delivery so it can be sent.</template>
                        </template>
                        <template v-else>
                            <Lock class="mr-1 inline size-3.5 align-[-2px] text-emerald-600" />
                            Issued as <span class="text-foreground font-medium">{{ summary.number }}</span>
                            <template v-if="invoice.issue_date"> on {{ formatShortDate(invoice.issue_date) }}</template
                            >. It can no longer be changed.
                        </template>
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <Button variant="outline" size="sm" class="gap-1.5" as-child>
                        <a :href="invoice.pdf_url" :download="invoice.pdf_filename" data-testid="download-pdf">
                            <Download class="size-3.5" />
                            {{ summary.status === 'issued' ? 'Download PDF' : 'Draft PDF' }}
                        </a>
                    </Button>
                    <template v-if="summary.status === 'approved' && canApprove">
                        <Button
                            variant="outline"
                            size="sm"
                            class="gap-1.5"
                            :disabled="cancelling"
                            data-testid="cancel-sending"
                            @click="cancelSending"
                        >
                            <Loader2 v-if="cancelling" class="size-3.5 animate-spin" />
                            <Undo2 v-else class="size-3.5" />
                            Cancel sending
                        </Button>
                        <Button
                            v-if="canSend"
                            variant="ghost"
                            size="sm"
                            class="gap-1.5"
                            :disabled="!mailConnected || sendingNow"
                            :title="mailConnected ? undefined : 'Connect email delivery before sending invoices.'"
                            data-testid="send-now"
                            @click="sendNowOpen = true"
                        >
                            <Send class="size-3.5" />
                            Send now
                        </Button>
                    </template>
                </div>
            </div>

            <!-- Time recorded after this draft was prepared -->
            <div
                v-if="trackedUpdate && canEdit"
                class="flex flex-col gap-3 rounded-2xl border border-sky-500/30 bg-sky-500/5 p-4 text-sm sm:flex-row sm:items-center"
                data-testid="tracked-update"
            >
                <Clock class="hidden size-4 shrink-0 text-sky-600 sm:block" />
                <p class="flex-1">
                    Recorded hours changed since this invoice was prepared:
                    <strong class="tabular-nums"
                        >{{ trackedUpdate.delta_seconds >= 0 ? '+' : '−' }}{{ (Math.abs(trackedUpdate.delta_seconds) / 3600).toFixed(2) }}h</strong
                    >
                    across {{ trackedUpdate.lines }} {{ trackedUpdate.lines === 1 ? 'person' : 'people' }}. Hours you changed yourself are kept.
                </p>
                <Button variant="outline" size="sm" class="gap-1.5" :disabled="refreshing" data-testid="refresh-hours" @click="refreshHours">
                    <RefreshCw class="size-3.5" />
                    Refresh recorded hours
                </Button>
            </div>

            <!-- Automatic sending is waiting for the owner, and why -->
            <div
                v-if="invoice.automation_reasons.length"
                class="flex flex-col gap-2 rounded-2xl border border-orange-500/30 bg-orange-500/5 p-4 text-sm"
                data-testid="automation-reasons"
            >
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4 shrink-0 text-orange-600" />
                    {{ summary.attention?.label === 'Ready to send' ? 'Ready to send' : 'Automatic sending paused for your review' }}
                </p>
                <ul class="space-y-1 pl-6">
                    <li v-for="reason in invoice.automation_reasons" :key="reason.code + reason.message" :data-reason="reason.code">
                        {{ reason.message }}
                    </li>
                </ul>
                <p class="text-muted-foreground pl-6">When it looks right, approve it below. It still waits {{ delayLabel }} before it is sent.</p>
            </div>

            <!-- Email not connected: nothing can be sent until it is -->
            <div
                v-if="summary.status === 'approved' && !mailConnected"
                class="flex flex-col gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm sm:flex-row sm:items-center"
                data-testid="mail-not-connected"
            >
                <AlertCircle class="hidden size-4 shrink-0 text-amber-600 sm:block" />
                <p class="flex-1">Connect email delivery before sending invoices. This invoice stays approved and waits until you do.</p>
                <Button v-if="canManageInvoicing" variant="outline" size="sm" as-child>
                    <Link :href="workspaceRoute('workspace.mail-settings.edit')">Connect email</Link>
                </Button>
            </div>

            <!-- Email delivery: its own state, the invoice stays issued whatever happens here -->
            <div
                v-if="delivery"
                class="flex flex-col gap-3 rounded-2xl border p-4 text-sm sm:flex-row sm:items-center"
                :class="delivery.status === 'failed' || delivery.status === 'needs_attention' ? 'border-destructive/30 bg-destructive/5' : 'bg-card'"
                data-testid="delivery"
                :data-status="delivery.status"
            >
                <Loader2 v-if="inFlight" class="hidden size-4 shrink-0 animate-spin text-sky-600 sm:block" />
                <Mail v-else-if="delivery.status === 'sent'" class="hidden size-4 shrink-0 text-emerald-600 sm:block" />
                <AlertCircle v-else class="text-destructive hidden size-4 shrink-0 sm:block" />
                <div class="min-w-0 flex-1">
                    <p v-if="inFlight" class="font-medium">Sending to {{ delivery.recipient }}…</p>
                    <p v-else-if="delivery.status === 'sent'">
                        <span class="font-medium">Sent to {{ delivery.recipient }}</span>
                        <span class="text-muted-foreground"
                            ><template v-if="delivery.cc.length"> · cc {{ delivery.cc.join(', ') }}</template
                            ><template v-if="delivery.sent_at"> · {{ formatDateTime(delivery.sent_at) }}</template></span
                        >
                    </p>
                    <template v-else>
                        <p class="font-medium">
                            {{ delivery.status === 'needs_attention' ? "We couldn't confirm the email was sent" : "The email wasn't sent" }}
                        </p>
                        <p class="text-muted-foreground" data-testid="delivery-error">
                            {{ delivery.error }} {{ summary.number }} is still issued; nothing about the invoice has changed.
                        </p>
                    </template>
                </div>
                <Button
                    v-if="canSend && (delivery.status === 'failed' || delivery.status === 'needs_attention')"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    :disabled="retrying"
                    data-testid="retry-sending"
                    @click="delivery.status === 'needs_attention' ? (retryOpen = true) : retry()"
                >
                    <Loader2 v-if="retrying" class="size-3.5 animate-spin" />
                    <RefreshCw v-else class="size-3.5" />
                    Retry sending
                </Button>
            </div>

            <Dialog v-model:open="sendNowOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Send this invoice now?</DialogTitle>
                        <DialogDescription>
                            It will be issued with its invoice number and emailed to {{ invoice.bill_to.billing_email }} straight away. After this it
                            can no longer be changed.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="outline">Not yet</Button>
                        </DialogClose>
                        <Button :disabled="sendingNow" data-testid="confirm-send-now" @click="sendNow">
                            <Loader2 v-if="sendingNow" class="mr-2 size-4 animate-spin" />
                            Send now
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="retryOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Send it again?</DialogTitle>
                        <DialogDescription>
                            Sending was interrupted after the email reached your mail server, so it may have arrived. Check your sent mail or ask the
                            client first. If you send again, they may get it twice.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button :disabled="retrying" data-testid="confirm-retry" @click="retry">Send again</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Payments live beside the document: the issued PDF never changes -->
            <InvoicePayments
                v-if="summary.status === 'issued'"
                :invoice="invoice"
                :can-record="canRecordPayments"
                :methods="paymentMethods"
                :today="today"
            />

            <!-- Sender details missing or out of date -->
            <div
                v-if="invoice.is_editable && (senderBlocksApproval || !invoice.bill_from)"
                class="flex flex-col gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm sm:flex-row sm:items-center"
                data-testid="sender-warning"
            >
                <AlertCircle class="hidden size-4 shrink-0 text-amber-600 sm:block" />
                <p class="flex-1">
                    <template v-if="!invoice.bill_from && hasCompleteInvoicingDetails">
                        This invoice was prepared before your invoicing details existed. They will be added when you approve it.
                    </template>
                    <template v-else>Complete your invoicing details (business name, email and address) before approving this invoice.</template>
                </p>
                <div class="flex gap-2">
                    <Button v-if="canManageInvoicing && !hasCompleteInvoicingDetails" variant="outline" size="sm" as-child>
                        <Link :href="workspaceRoute('workspace.invoicing.edit')">Open invoicing settings</Link>
                    </Button>
                    <Button
                        v-if="canEdit && hasCompleteInvoicingDetails"
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        :disabled="refreshingSender"
                        @click="useCurrentSender"
                    >
                        <RefreshCw class="size-3.5" />
                        Use current invoicing details
                    </Button>
                </div>
            </div>

            <!-- The invoice document -->
            <article class="bg-card overflow-hidden rounded-2xl border shadow-sm" data-testid="invoice-paper">
                <div class="space-y-10 p-6 sm:p-10">
                    <!-- From / Invoice -->
                    <header class="flex flex-col gap-8 sm:flex-row sm:justify-between">
                        <div class="min-w-0 text-sm">
                            <img
                                v-if="invoice.logo_url"
                                :src="invoice.logo_url"
                                alt=""
                                class="mb-4 max-h-14 max-w-[200px] object-contain"
                                data-testid="invoice-logo"
                            />
                            <template v-if="from">
                                <p class="text-base font-semibold" data-testid="sender-name">{{ from.business_name }}</p>
                                <p v-if="from.legal_name" class="text-muted-foreground">{{ from.legal_name }}</p>
                                <p v-for="line in fromLines" :key="line" class="text-muted-foreground">{{ line }}</p>
                                <p class="text-muted-foreground">
                                    {{ from.billing_email }}<template v-if="from.phone"> · {{ from.phone }}</template>
                                </p>
                                <p v-if="from.tax_id" class="text-muted-foreground">Tax ID {{ from.tax_id }}</p>
                            </template>
                            <p v-else class="text-muted-foreground italic">Your business details appear here</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="text-2xl font-semibold tracking-[0.18em]">INVOICE</p>
                            <p v-if="summary.number" class="mt-1 font-medium tabular-nums" data-testid="invoice-number">{{ summary.number }}</p>
                            <p v-else class="text-muted-foreground mt-1.5 inline-block rounded border px-2 py-0.5 text-xs tracking-[0.14em]">DRAFT</p>
                            <dl class="mt-4 grid grid-cols-[auto_auto] gap-x-6 gap-y-1 text-sm sm:justify-end">
                                <template v-if="invoice.issue_date">
                                    <dt class="text-muted-foreground">Issue date</dt>
                                    <dd class="tabular-nums">{{ formatShortDate(invoice.issue_date) }}</dd>
                                    <dt class="text-muted-foreground">Due date</dt>
                                    <dd class="tabular-nums">{{ invoice.due_date ? formatShortDate(invoice.due_date) : '—' }}</dd>
                                </template>
                                <template v-else>
                                    <dt class="text-muted-foreground">Planned send</dt>
                                    <dd class="tabular-nums">{{ formatShortDate(summary.planned_send_on) }}</dd>
                                    <dt class="text-muted-foreground">Payment due</dt>
                                    <dd>{{ invoice.due_in_days }} days after issue</dd>
                                </template>
                            </dl>
                        </div>
                    </header>

                    <!-- Bill to / period -->
                    <div class="grid gap-6 border-t pt-8 text-sm sm:grid-cols-2">
                        <div>
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.14em] uppercase">Bill to</p>
                            <p class="mt-2 text-base font-semibold">{{ invoice.bill_to.name }}</p>
                            <p class="text-muted-foreground break-words">{{ invoice.bill_to.billing_email }}</p>
                            <p v-if="invoice.bill_to.address" class="text-muted-foreground whitespace-pre-line">{{ invoice.bill_to.address }}</p>
                            <p v-if="invoice.bill_to.tax_id" class="text-muted-foreground">Tax ID {{ invoice.bill_to.tax_id }}</p>
                        </div>
                        <div class="sm:text-right">
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.14em] uppercase">Invoice period</p>
                            <p class="mt-2">
                                {{ formatShortDate(summary.period_start) }} – {{ formatShortDate(summary.period_end) }},
                                {{ summary.period_start.slice(0, 4) }}
                            </p>
                            <p class="text-muted-foreground">{{ summary.title }}</p>
                        </div>
                    </div>

                    <!-- Lines -->
                    <div>
                        <div
                            class="text-muted-foreground hidden gap-4 border-b pb-2 text-[11px] font-semibold tracking-[0.12em] uppercase sm:grid"
                            :class="columnClass"
                        >
                            <span>Description</span>
                            <template v-if="isHourly">
                                <span class="text-right">Hours</span>
                                <span class="text-right">Rate</span>
                            </template>
                            <span class="text-right">Amount</span>
                        </div>

                        <ul class="divide-y" data-testid="invoice-lines">
                            <li
                                v-for="(line, index) in invoice.lines"
                                :key="line.position"
                                :class="[isHourly ? 'flex-col' : 'flex-row items-start justify-between', columnClass]"
                                class="flex gap-2 py-3.5 sm:grid sm:items-center sm:gap-4"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">{{ line.description }}</p>
                                    <p v-if="line.role_label" class="text-muted-foreground text-xs">{{ line.role_label }}</p>
                                    <p
                                        v-if="isHourly && line.tracked_quantity_centi !== null"
                                        class="text-muted-foreground text-xs tabular-nums"
                                        :data-testid="`recorded-${index}`"
                                    >
                                        {{
                                            line.tracked_quantity_centi === 0
                                                ? 'No tracked time'
                                                : `Recorded ${(line.tracked_quantity_centi / 100).toFixed(2)}h`
                                        }}
                                        <template v-if="!editable && line.quantity_reason">
                                            · Billed differently: {{ line.quantity_reason }}</template
                                        >
                                    </p>
                                </div>

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
                                        <p v-else class="text-sm tabular-nums sm:text-right">
                                            {{ line.quantity_centi === null ? '—' : (line.quantity_centi / 100).toFixed(2) }}
                                        </p>
                                        <p class="text-muted-foreground text-sm tabular-nums sm:text-right">{{ money(line.unit_price_minor) }}</p>
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
                                <div v-if="isHourly && editable && billedDiffers(line)" class="col-span-full grid gap-1">
                                    <input
                                        v-model="reasons[line.position]"
                                        class="h-9 rounded-md border px-2 text-sm"
                                        :class="errors[`reasons.${line.position}`] && 'border-destructive'"
                                        placeholder="Why bill different hours? e.g. Internal training"
                                        :aria-label="`Reason for billing different hours for ${line.description}`"
                                        :data-testid="`reason-${index}`"
                                        @input="edited(line.position)"
                                        @blur="save"
                                        @keydown.enter.prevent="save"
                                    />
                                    <p v-if="errors[`reasons.${line.position}`]" class="text-destructive text-xs">
                                        {{ errors[`reasons.${line.position}`] }}
                                    </p>
                                </div>
                            </li>
                        </ul>

                        <!-- Totals -->
                        <dl class="mt-4 ml-auto w-full space-y-2 border-t pt-4 text-sm sm:w-80">
                            <div class="text-muted-foreground flex justify-between">
                                <dt>Subtotal</dt>
                                <dd class="tabular-nums" data-testid="invoice-subtotal">{{ money(shown.subtotal) }}</dd>
                            </div>
                            <div
                                v-for="(adjustment, index) in invoice.adjustments"
                                :key="index"
                                class="text-muted-foreground flex justify-between gap-3"
                            >
                                <dt>
                                    {{
                                        adjustment.type === 'percentage'
                                            ? `${adjustment.label} ${basisPointsToInput(adjustment.value)}%`
                                            : adjustment.label
                                    }}
                                </dt>
                                <dd class="tabular-nums">{{ money(shown.adjustments[index] ?? 0) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between border-t pt-3">
                                <dt class="font-semibold">{{ shown.missing ? 'Total so far' : `Total ${summary.currency}` }}</dt>
                                <dd class="text-2xl font-semibold tracking-tight tabular-nums" data-testid="invoice-total">
                                    {{ money(shown.total) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <p v-if="editable" class="bg-muted/40 text-muted-foreground border-t px-6 py-3 text-xs sm:px-10">
                    Changes here apply to this invoice only.
                </p>
            </article>
        </div>

        <!-- Action bar while the invoice can still be edited -->
        <div v-if="canApprove && invoice.is_editable" class="bg-background/95 sticky bottom-0 z-10 border-t py-3 pr-24 pl-4 backdrop-blur sm:pl-6">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3">
                <p class="text-muted-foreground text-xs" data-testid="save-state">
                    <template v-if="saving"><Loader2 class="mr-1 inline size-3.5 animate-spin align-[-2px]" />Saving…</template>
                    <template v-else-if="summary.status === 'needs_hours'">Approve becomes available once every person has hours.</template>
                    <template v-else-if="senderBlocksApproval">Add your invoicing details to approve.</template>
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
