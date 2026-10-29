<script setup lang="ts">
import { ChevronLeft, Loader2, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney, parseScaled } from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };
type ClientOption = { public_id: string; name: string; currency: string; billing_email: string };
type Line = { description: string; quantity: string; rate: string };
type Adjustment = { kind: string; type: string; value: string; label: string | null };
type Existing = {
    public_id: string;
    version: number;
    recipient_type: 'client' | 'custom';
    client: string | null;
    bill_to: { name: string; billing_email: string; address: string | null; tax_id: string | null };
    title: string;
    currency: string;
    issue_date: string;
    due_date: string;
    notes: string | null;
    lines: Line[];
    adjustments: Adjustment[];
};

const props = defineProps<{
    clients: ClientOption[];
    currencies: Option[];
    adjustmentKinds: Option[];
    defaults: { issue_date: string; due_date: string };
    hasCompleteInvoicingDetails: boolean;
    invoice: Existing | null;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const editing = computed(() => props.invoice !== null);
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: workspaceRoute('workspace.invoices.index') },
    { title: editing.value ? 'Edit draft' : 'New invoice', href: '#' },
];

const form = useForm({
    idempotency_key: props.invoice ? undefined : crypto.randomUUID(),
    version: props.invoice?.version,
    recipient_type: props.invoice?.recipient_type ?? (props.clients.length ? 'client' : 'custom'),
    client: props.invoice?.client ?? props.clients[0]?.public_id ?? '',
    bill_to: {
        name: props.invoice?.recipient_type === 'custom' ? props.invoice.bill_to.name : '',
        billing_email: props.invoice?.recipient_type === 'custom' ? props.invoice.bill_to.billing_email : '',
        address: props.invoice?.recipient_type === 'custom' ? (props.invoice.bill_to.address ?? '') : '',
        tax_id: props.invoice?.recipient_type === 'custom' ? (props.invoice.bill_to.tax_id ?? '') : '',
    },
    title: props.invoice?.title ?? '',
    currency: props.invoice?.currency ?? props.clients[0]?.currency ?? 'USD',
    issue_date: props.invoice?.issue_date ?? props.defaults.issue_date,
    due_date: props.invoice?.due_date ?? props.defaults.due_date,
    lines: props.invoice?.lines.length ? props.invoice.lines.map((line) => ({ ...line })) : [{ description: '', quantity: '1', rate: '' }],
    adjustments: (props.invoice?.adjustments ?? []).map((adjustment) => ({ ...adjustment, label: adjustment.label ?? '' })),
    notes: props.invoice?.notes ?? '',
});

watch(
    () => form.client,
    (publicId) => {
        const client = props.clients.find((option) => option.public_id === publicId);
        if (client && !editing.value) form.currency = client.currency;
    },
);

const errors = computed(() => form.errors as Record<string, string | undefined>);

const halfUp = (numerator: number, denominator: number) => Math.floor((numerator * 2 + denominator) / (2 * denominator));
const lineAmount = (line: Line) => {
    const quantity = parseScaled(line.quantity);
    const rate = parseScaled(line.rate);
    return quantity === null || rate === null ? null : halfUp(rate * quantity, 100);
};
const subtotal = computed(() => form.lines.reduce((sum, line) => sum + (lineAmount(line) ?? 0), 0));
const adjustmentAmount = (adjustment: Adjustment) => {
    const value = parseScaled(adjustment.value) ?? 0;
    const amount = adjustment.type === 'percentage' ? halfUp(subtotal.value * value, 10000) : value;
    return adjustment.kind === 'discount' ? -amount : amount;
};
const total = computed(() => subtotal.value + form.adjustments.reduce((sum, adjustment) => sum + adjustmentAmount(adjustment), 0));

function addLine() {
    form.lines.push({ description: '', quantity: '1', rate: '' });
}

function removeLine(index: number) {
    if (form.lines.length > 1) form.lines.splice(index, 1);
}

function addAdjustment(kind: string) {
    form.adjustments.push({ kind, type: 'percentage', value: '', label: '' });
}

function submit() {
    if (form.processing) return;
    if (props.invoice) {
        form.put(workspaceRoute('workspace.invoices.update', { invoice: props.invoice.public_id }), { preserveScroll: true });
    } else {
        form.post(workspaceRoute('workspace.invoices.store'), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="editing ? 'Edit invoice' : 'New invoice'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form
            class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
            data-testid="manual-invoice-form"
            @submit.prevent="submit"
        >
            <Link
                :href="
                    invoice ? workspaceRoute('workspace.invoices.show', { invoice: invoice.public_id }) : workspaceRoute('workspace.invoices.index')
                "
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" /> {{ invoice ? 'Invoice' : 'Invoices' }}
            </Link>
            <AppPageHeader
                eyebrow="Money"
                :title="editing ? 'Edit draft invoice' : 'New invoice'"
                description="A one-off invoice with your own lines. It stays a draft until you finalize or send it."
            />

            <div v-if="!hasCompleteInvoicingDetails" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm">
                Add your invoicing details before finalizing.
                <Link :href="workspaceRoute('workspace.invoicing.edit')" class="font-medium underline">Invoicing settings</Link>
            </div>

            <section class="bg-card space-y-4 rounded-2xl border p-5">
                <h2 class="font-semibold">Bill to</h2>
                <AppSegmentedControl
                    v-model="form.recipient_type"
                    :options="[
                        { value: 'client', label: 'Existing client' },
                        { value: 'custom', label: 'Someone else' },
                    ]"
                />
                <div v-if="form.recipient_type === 'client'" class="grid gap-1.5">
                    <Label for="invoice-client" class="text-sm font-medium">Client</Label>
                    <select
                        id="invoice-client"
                        v-model="form.client"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                        data-testid="invoice-client"
                    >
                        <option v-for="client in clients" :key="client.public_id" :value="client.public_id">
                            {{ client.name }} · {{ client.billing_email }}
                        </option>
                    </select>
                    <InputError :message="errors.client" />
                </div>
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <AppFormInput id="bill-to-name" v-model="form.bill_to.name" label="Name" :error="errors['bill_to.name']" required />
                    <AppFormInput
                        id="bill-to-email"
                        v-model="form.bill_to.billing_email"
                        type="email"
                        label="Billing email"
                        :error="errors['bill_to.billing_email']"
                        required
                    />
                    <div class="grid gap-1.5 sm:col-span-2">
                        <Label for="bill-to-address" class="text-sm font-medium"
                            >Address <span class="text-muted-foreground font-normal">(optional)</span></Label
                        >
                        <Textarea id="bill-to-address" v-model="form.bill_to.address" rows="2" />
                        <InputError :message="errors['bill_to.address']" />
                    </div>
                    <AppFormInput id="bill-to-tax" v-model="form.bill_to.tax_id" label="Tax ID" hint="Optional" :error="errors['bill_to.tax_id']" />
                    <p class="text-muted-foreground text-xs sm:col-span-2">Used for this invoice only; no client record is created or changed.</p>
                </div>
            </section>

            <section class="bg-card grid gap-4 rounded-2xl border p-5 sm:grid-cols-2">
                <AppFormInput
                    id="invoice-title"
                    v-model="form.title"
                    label="Title"
                    placeholder="e.g. Website audit"
                    class="sm:col-span-2"
                    :error="errors.title"
                    required
                />
                <div class="grid gap-1.5">
                    <Label for="invoice-issue" class="text-sm font-medium">Issue date</Label>
                    <input id="invoice-issue" v-model="form.issue_date" type="date" class="bg-background h-10 rounded-md border px-2 text-sm" />
                    <InputError :message="errors.issue_date" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="invoice-due" class="text-sm font-medium">Due date</Label>
                    <input
                        id="invoice-due"
                        v-model="form.due_date"
                        type="date"
                        :min="form.issue_date"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                    />
                    <InputError :message="errors.due_date" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="invoice-currency" class="text-sm font-medium">Currency</Label>
                    <select id="invoice-currency" v-model="form.currency" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.value }}</option>
                    </select>
                    <InputError :message="errors.currency" />
                </div>
            </section>

            <section class="bg-card space-y-3 rounded-2xl border p-5" data-testid="invoice-lines">
                <h2 class="font-semibold">Lines</h2>
                <div class="text-muted-foreground hidden grid-cols-[1fr_7rem_8rem_8rem_2rem] gap-2 text-xs sm:grid">
                    <span>Description</span><span>Quantity</span><span>Rate</span><span class="text-right">Amount</span><span></span>
                </div>
                <div v-for="(line, index) in form.lines" :key="index" class="grid gap-2 sm:grid-cols-[1fr_7rem_8rem_8rem_2rem] sm:items-start">
                    <div>
                        <input
                            v-model="line.description"
                            placeholder="What was delivered"
                            class="bg-background h-10 w-full rounded-md border px-3 text-sm"
                            :aria-label="`Line ${index + 1} description`"
                        />
                        <InputError :message="errors[`lines.${index}.description`]" />
                    </div>
                    <div>
                        <input
                            v-model="line.quantity"
                            inputmode="decimal"
                            class="bg-background h-10 w-full rounded-md border px-3 text-sm tabular-nums"
                            :aria-label="`Line ${index + 1} quantity`"
                        />
                        <InputError :message="errors[`lines.${index}.quantity`]" />
                    </div>
                    <div>
                        <input
                            v-model="line.rate"
                            inputmode="decimal"
                            placeholder="0.00"
                            class="bg-background h-10 w-full rounded-md border px-3 text-sm tabular-nums"
                            :aria-label="`Line ${index + 1} rate`"
                        />
                        <InputError :message="errors[`lines.${index}.rate`]" />
                    </div>
                    <p class="py-2 text-right text-sm tabular-nums">
                        {{ lineAmount(line) === null ? '—' : formatMoney(lineAmount(line)!, form.currency) }}
                    </p>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-destructive p-2"
                        :disabled="form.lines.length === 1"
                        :aria-label="`Remove line ${index + 1}`"
                        @click="removeLine(index)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
                <InputError :message="errors.lines" />
                <Button type="button" variant="outline" size="sm" class="gap-1.5" :disabled="form.lines.length >= 50" @click="addLine"
                    ><Plus class="size-3.5" /> Add line</Button
                >
            </section>

            <section class="bg-card space-y-3 rounded-2xl border p-5" data-testid="invoice-adjustments">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Tax and discount <span class="text-muted-foreground text-sm font-normal">(optional)</span></h2>
                    <div class="flex gap-2">
                        <Button
                            v-for="kind in adjustmentKinds"
                            :key="kind.value"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="form.adjustments.length >= 10"
                            @click="addAdjustment(kind.value)"
                            >+ {{ kind.label }}</Button
                        >
                    </div>
                </div>
                <div
                    v-for="(adjustment, index) in form.adjustments"
                    :key="index"
                    class="grid gap-2 sm:grid-cols-[7rem_1fr_8rem_8rem_8rem_2rem] sm:items-start"
                >
                    <span class="py-2 text-sm font-medium capitalize">{{ adjustment.kind }}</span>
                    <input
                        v-model="adjustment.label"
                        placeholder="Label on invoice (optional)"
                        class="bg-background h-10 rounded-md border px-3 text-sm"
                    />
                    <select v-model="adjustment.type" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option value="percentage">Percent</option>
                        <option value="fixed">Fixed amount</option>
                    </select>
                    <div>
                        <input
                            v-model="adjustment.value"
                            inputmode="decimal"
                            :placeholder="adjustment.type === 'percentage' ? '%' : '0.00'"
                            class="bg-background h-10 w-full rounded-md border px-3 text-sm tabular-nums"
                        />
                        <InputError :message="errors[`adjustments.${index}.value`]" />
                    </div>
                    <p class="py-2 text-right text-sm tabular-nums">{{ formatMoney(adjustmentAmount(adjustment), form.currency) }}</p>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-destructive p-2"
                        aria-label="Remove"
                        @click="form.adjustments.splice(index, 1)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
                <dl class="ml-auto w-full max-w-xs space-y-1 border-t pt-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd class="tabular-nums">{{ formatMoney(subtotal, form.currency) }}</dd>
                    </div>
                    <div class="flex justify-between font-semibold" data-testid="form-total">
                        <dt>Total {{ form.currency }}</dt>
                        <dd class="tabular-nums">{{ formatMoney(total, form.currency) }}</dd>
                    </div>
                </dl>
                <p class="text-muted-foreground text-right text-xs">Final totals are calculated by SprintSync when you save.</p>
            </section>

            <section class="bg-card space-y-1.5 rounded-2xl border p-5">
                <Label for="invoice-notes" class="font-semibold"
                    >Notes <span class="text-muted-foreground text-sm font-normal">(optional, printed on the invoice)</span></Label
                >
                <Textarea id="invoice-notes" v-model="form.notes" rows="3" placeholder="Payment details, thank-you note…" />
                <InputError :message="errors.notes" />
            </section>

            <div class="flex justify-end gap-2">
                <Button as-child variant="outline">
                    <Link
                        :href="
                            invoice
                                ? workspaceRoute('workspace.invoices.show', { invoice: invoice.public_id })
                                : workspaceRoute('workspace.invoices.index')
                        "
                        >Cancel</Link
                    >
                </Button>
                <Button type="submit" :disabled="form.processing" data-testid="save-invoice">
                    <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                    {{ editing ? 'Save draft' : 'Create draft' }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
