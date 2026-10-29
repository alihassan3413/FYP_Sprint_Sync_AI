<script setup lang="ts">
import { Ban, CheckCircle2, ChevronLeft, Download, Eye, Loader2, Mail, Pencil, Stamp } from 'lucide-vue-next';

import InvoicePayments from '@/components/billing/InvoicePayments.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { DISPLAY_STATUS_STYLES, formatMoney, formatShortDate, type DisplayStatus } from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';
import type { InvoiceData } from '@/types/generated';

const props = defineProps<{
    invoice: InvoiceData;
    canEdit: boolean;
    canSend: boolean;
    canFinalize: boolean;
    canVoid: boolean;
    canRecordPayments: boolean;
    paymentMethods: { value: string; label: string }[];
    today: string;
    mailConnected: boolean;
    hasCompleteInvoicingDetails: boolean;
    suggestedRecipients: { to: string[]; cc: string[] };
}>();

const { workspaceRoute } = useCurrentWorkspace();
const summary = computed(() => props.invoice.summary);
const status = computed(() => (summary.value.display_status ?? 'draft') as DisplayStatus);
const money = (minor: number) => formatMoney(minor, summary.value.currency);
const routeFor = (name: string) => workspaceRoute(name, { invoice: summary.value.public_id });
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Invoices', href: workspaceRoute('workspace.invoices.index') },
    { title: summary.value.number ?? 'Draft invoice', href: routeFor('workspace.invoices.show') },
]);

const pdfUrl = computed(() => routeFor('workspace.invoices.pdf'));
const previewUrl = computed(() => `${pdfUrl.value}?preview=1`);

const finalizing = ref(false);
function finalize() {
    if (finalizing.value) return;
    finalizing.value = true;
    router.post(routeFor('workspace.invoices.finalize'), {}, { preserveScroll: true, onFinish: () => (finalizing.value = false) });
}

const isSendOpen = ref(false);
const sendForm = useForm({ to: '', cc: '', bcc: '', message: '' });
function openSend() {
    sendForm.defaults({ to: props.suggestedRecipients.to.join(', '), cc: props.suggestedRecipients.cc.join(', '), bcc: '', message: '' });
    sendForm.reset();
    sendForm.clearErrors();
    isSendOpen.value = true;
}
function send() {
    if (sendForm.processing) return;
    sendForm.post(routeFor('workspace.invoices.send'), { preserveScroll: true, onSuccess: () => (isSendOpen.value = false) });
}
const sendErrors = computed(() => {
    const errors = sendForm.errors as Record<string, string | undefined>;
    const first = (prefix: string) => errors[prefix] ?? Object.entries(errors).find(([key]) => key.startsWith(`${prefix}.`))?.[1];
    return { to: first('to'), cc: first('cc'), bcc: first('bcc'), message: errors.message };
});

const isPaidOpen = ref(false);
const paidForm = useForm({ idempotency_key: '', received_on: '', method: 'bank_transfer', reference: '' });
function openPaid() {
    paidForm.defaults({ idempotency_key: crypto.randomUUID(), received_on: props.today, method: 'bank_transfer', reference: '' });
    paidForm.reset();
    paidForm.clearErrors();
    isPaidOpen.value = true;
}
function markPaid() {
    if (paidForm.processing) return;
    paidForm.post(routeFor('workspace.invoices.mark-paid'), { preserveScroll: true, onSuccess: () => (isPaidOpen.value = false) });
}

const isVoidOpen = ref(false);
const voidForm = useForm({ reason: '' });
function voidInvoice() {
    if (voidForm.processing) return;
    voidForm.post(routeFor('workspace.invoices.void'), { preserveScroll: true, onSuccess: () => (isVoidOpen.value = false) });
}

const from = computed(() => props.invoice.bill_from as Record<string, string | null> | null);
const to = computed(() => props.invoice.bill_to as Record<string, string | null>);
</script>

<template>
    <Head :title="summary.number ?? 'Draft invoice'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <Link
                :href="workspaceRoute('workspace.invoices.index')"
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" /> Invoices
            </Link>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.12em] uppercase">Invoice</p>
                    <h1 class="mt-1 flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">
                        {{ summary.number ?? 'Draft' }} · {{ summary.title }}
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="DISPLAY_STATUS_STYLES[status]"
                            data-testid="invoice-status"
                            >{{ summary.display_status_label }}</span
                        >
                        <span
                            v-if="status === 'sent' && !invoice.delivery"
                            class="text-muted-foreground text-xs font-normal"
                            data-testid="not-emailed"
                            >Finalized · not emailed yet</span
                        >
                    </h1>
                    <p class="text-muted-foreground mt-1 text-sm">{{ summary.client_name }} · {{ money(summary.total_minor) }}</p>
                </div>
                <div class="flex flex-wrap gap-2" data-testid="invoice-actions">
                    <Button v-if="canEdit" as-child size="sm" variant="outline" class="gap-1.5">
                        <Link :href="routeFor('workspace.invoices.edit')"><Pencil class="size-3.5" /> Edit</Link>
                    </Button>
                    <Button as-child size="sm" variant="outline" class="gap-1.5">
                        <a :href="previewUrl" target="_blank" rel="noopener" data-testid="preview-pdf"><Eye class="size-3.5" /> Preview</a>
                    </Button>
                    <Button as-child size="sm" variant="outline" class="gap-1.5">
                        <a :href="pdfUrl" data-testid="download-pdf"><Download class="size-3.5" /> Download PDF</a>
                    </Button>
                    <Button
                        v-if="canFinalize"
                        size="sm"
                        variant="outline"
                        class="gap-1.5"
                        :disabled="finalizing || !hasCompleteInvoicingDetails"
                        data-testid="finalize"
                        @click="finalize"
                    >
                        <Loader2 v-if="finalizing" class="size-3.5 animate-spin" /><Stamp v-else class="size-3.5" /> Finalize
                    </Button>
                    <Button
                        v-if="canSend"
                        size="sm"
                        class="gap-1.5"
                        :disabled="!mailConnected || !hasCompleteInvoicingDetails"
                        data-testid="open-send"
                        @click="openSend"
                    >
                        <Mail class="size-3.5" /> Send
                    </Button>
                    <Button
                        v-if="canRecordPayments && summary.balance_due_minor > 0"
                        size="sm"
                        variant="outline"
                        class="gap-1.5"
                        data-testid="open-mark-paid"
                        @click="openPaid"
                    >
                        <CheckCircle2 class="size-3.5" /> Mark paid
                    </Button>
                    <Button
                        v-if="canVoid"
                        size="sm"
                        variant="ghost"
                        class="text-destructive gap-1.5"
                        data-testid="open-void"
                        @click="isVoidOpen = true"
                    >
                        <Ban class="size-3.5" /> Void
                    </Button>
                </div>
            </div>

            <div v-if="invoice.voided_at" class="bg-muted/60 rounded-2xl px-5 py-4 text-sm" data-testid="void-banner">
                <span class="font-medium">Voided.</span> {{ invoice.void_reason }} It stays on record and is not owed.
            </div>
            <div
                v-else-if="status === 'draft' && !hasCompleteInvoicingDetails"
                class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-5 py-4 text-sm"
            >
                Add your invoicing details before finalizing or sending.
                <Link :href="workspaceRoute('workspace.invoicing.edit')" class="font-medium underline">Invoicing settings</Link>
            </div>
            <div v-else-if="canSend && !mailConnected" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-5 py-4 text-sm">
                Connect email delivery to send invoices. You can still finalize and download the PDF.
                <Link :href="workspaceRoute('workspace.mail-settings.edit')" class="font-medium underline">Email delivery</Link>
            </div>
            <p v-else-if="status === 'draft'" class="text-muted-foreground text-sm">
                Draft: nothing has been sent. Finalize to give it a number and lock it, or Send to finalize and email it in one step.
            </p>

            <section v-if="invoice.delivery" class="bg-card rounded-2xl border p-5 text-sm" data-testid="delivery">
                <p class="font-medium">{{ invoice.delivery.status_label }}</p>
                <p class="text-muted-foreground mt-1">
                    To {{ invoice.delivery.recipients.join(', ')
                    }}<template v-if="invoice.delivery.cc.length"> · cc {{ invoice.delivery.cc.join(', ') }}</template
                    ><template v-if="invoice.delivery.bcc.length"> · bcc {{ invoice.delivery.bcc.length }}</template>
                </p>
                <p v-if="invoice.delivery.error" class="text-destructive mt-1">{{ invoice.delivery.error }}</p>
            </section>

            <InvoicePayments
                v-if="summary.status === 'issued' && !invoice.voided_at"
                :invoice="invoice"
                :can-record="canRecordPayments"
                :methods="paymentMethods"
                :today="today"
            />

            <article class="bg-card rounded-2xl border p-6 sm:p-8" data-testid="invoice-document">
                <div class="flex flex-col justify-between gap-6 sm:flex-row">
                    <div class="text-sm">
                        <p class="text-muted-foreground text-xs font-semibold uppercase">From</p>
                        <template v-if="from">
                            <p class="mt-1 font-semibold">{{ from.business_name }}</p>
                            <p class="text-muted-foreground">{{ from.billing_email }}</p>
                        </template>
                        <p v-else class="text-muted-foreground mt-1">Your invoicing details</p>
                    </div>
                    <dl class="grid grid-cols-[auto_auto] gap-x-6 gap-y-1 text-sm sm:text-right">
                        <dt class="text-muted-foreground">Issue date</dt>
                        <dd>{{ summary.issue_date ? formatShortDate(summary.issue_date) : '—' }}</dd>
                        <dt class="text-muted-foreground">Due date</dt>
                        <dd>{{ summary.due_date ? formatShortDate(summary.due_date) : '—' }}</dd>
                    </dl>
                </div>
                <div class="mt-6 text-sm">
                    <p class="text-muted-foreground text-xs font-semibold uppercase">Bill to</p>
                    <p class="mt-1 font-semibold">{{ to.name }}</p>
                    <p class="text-muted-foreground">{{ to.billing_email }}</p>
                    <p v-if="to.address" class="text-muted-foreground whitespace-pre-line">{{ to.address }}</p>
                    <p v-if="to.tax_id" class="text-muted-foreground">Tax ID {{ to.tax_id }}</p>
                </div>
                <div class="mt-6 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-muted-foreground border-b text-left text-xs">
                            <tr>
                                <th class="py-2 font-medium">Description</th>
                                <th class="py-2 text-right font-medium">Qty</th>
                                <th class="py-2 text-right font-medium">Rate</th>
                                <th class="py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="line in invoice.lines" :key="line.position">
                                <td class="py-2">{{ line.description }}</td>
                                <td class="py-2 text-right">{{ line.quantity_centi === null ? '—' : (line.quantity_centi / 100).toFixed(2) }}</td>
                                <td class="py-2 text-right">{{ money(line.unit_price_minor) }}</td>
                                <td class="py-2 text-right">{{ line.amount_minor === null ? '—' : money(line.amount_minor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <dl class="mt-4 ml-auto w-full max-w-xs space-y-1 text-sm tabular-nums">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd>{{ money(invoice.subtotal_minor) }}</dd>
                    </div>
                    <div v-for="adjustment in invoice.adjustments" :key="adjustment.label + adjustment.kind" class="flex justify-between">
                        <dt class="text-muted-foreground">
                            {{ adjustment.type === 'percentage' ? `${adjustment.label} ${adjustment.value / 100}%` : adjustment.label }}
                        </dt>
                        <dd>{{ money(adjustment.amount_minor) }}</dd>
                    </div>
                    <div class="flex justify-between border-t pt-2 text-base font-semibold" data-testid="invoice-total">
                        <dt>Total {{ summary.currency }}</dt>
                        <dd>{{ money(summary.total_minor) }}</dd>
                    </div>
                </dl>
                <div v-if="invoice.notes" class="text-muted-foreground mt-6 border-t pt-4 text-sm whitespace-pre-line" data-testid="invoice-notes">
                    {{ invoice.notes }}
                </div>
            </article>
        </div>
    </AppLayout>

    <AppModal
        :open="isSendOpen"
        title="Send invoice"
        description="The PDF is attached. These addresses are used for this email only; client records are not changed."
        @update:open="(value) => !sendForm.processing && (isSendOpen = value)"
    >
        <form id="send-invoice-form" class="space-y-4 pt-1" @submit.prevent="send">
            <AppFormInput
                id="send-to"
                v-model="sendForm.to"
                label="To"
                hint="Separate several addresses with commas"
                :error="sendErrors.to"
                required
            />
            <AppFormInput id="send-cc" v-model="sendForm.cc" label="Cc" hint="Optional" :error="sendErrors.cc" />
            <AppFormInput id="send-bcc" v-model="sendForm.bcc" label="Bcc" hint="Optional" :error="sendErrors.bcc" />
            <div class="grid gap-1.5">
                <Label for="send-message" class="text-sm font-medium"
                    >Message <span class="text-muted-foreground font-normal">(optional)</span></Label
                >
                <Textarea id="send-message" v-model="sendForm.message" rows="4" placeholder="Added above the standard invoice text" />
                <InputError :message="sendErrors.message" />
            </div>
            <p v-if="status === 'draft'" class="text-muted-foreground text-xs">
                Sending finalizes the invoice: it gets a number and can no longer be edited.
            </p>
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="sendForm.processing" @click="isSendOpen = false">Cancel</Button>
            <Button type="submit" form="send-invoice-form" :disabled="sendForm.processing" data-testid="confirm-send">
                <Loader2 v-if="sendForm.processing" class="mr-2 size-4 animate-spin" /> Send invoice
            </Button>
        </template>
    </AppModal>

    <AppModal
        :open="isPaidOpen"
        title="Mark as paid"
        :description="`Records a payment of ${money(summary.balance_due_minor)}.`"
        size="sm"
        @update:open="(value) => !paidForm.processing && (isPaidOpen = value)"
    >
        <form id="mark-paid-form" class="space-y-4 pt-1" @submit.prevent="markPaid">
            <div class="grid gap-1.5">
                <Label for="paid-date" class="text-sm font-medium">Date received</Label>
                <input
                    id="paid-date"
                    v-model="paidForm.received_on"
                    type="date"
                    :max="today"
                    class="bg-background h-10 rounded-md border px-2 text-sm"
                />
                <InputError :message="paidForm.errors.received_on" />
            </div>
            <div class="grid gap-1.5">
                <Label for="paid-method" class="text-sm font-medium">Method</Label>
                <select id="paid-method" v-model="paidForm.method" class="bg-background h-10 rounded-md border px-2 text-sm">
                    <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                </select>
            </div>
            <AppFormInput id="paid-reference" v-model="paidForm.reference" label="Reference" hint="Optional" :error="paidForm.errors.reference" />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="paidForm.processing" @click="isPaidOpen = false">Cancel</Button>
            <Button type="submit" form="mark-paid-form" :disabled="paidForm.processing" data-testid="confirm-mark-paid">Mark paid</Button>
        </template>
    </AppModal>

    <AppModal
        :open="isVoidOpen"
        title="Void this invoice?"
        description="It stays on record with its number but is no longer owed. Record a new invoice if you need a correction."
        size="sm"
        @update:open="(value) => !voidForm.processing && (isVoidOpen = value)"
    >
        <form id="void-invoice-form" class="pt-1" @submit.prevent="voidInvoice">
            <AppFormInput
                id="void-invoice-reason"
                v-model="voidForm.reason"
                label="Reason"
                placeholder="e.g. billed in error"
                :error="voidForm.errors.reason"
                required
            />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="voidForm.processing" @click="isVoidOpen = false">Cancel</Button>
            <Button type="submit" form="void-invoice-form" variant="destructive" :disabled="voidForm.processing" data-testid="confirm-void-invoice"
                >Void invoice</Button
            >
        </template>
    </AppModal>
</template>
