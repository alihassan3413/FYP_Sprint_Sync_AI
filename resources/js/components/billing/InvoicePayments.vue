<script setup lang="ts">
import { Ban, Loader2, Plus, Wallet } from 'lucide-vue-next';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import { formatMoney, formatShortDate, minorToInput, parseScaled, PAYMENT_STATUS_STYLES } from '@/lib/billing';
import type { InvoiceData, PaymentData } from '@/types/generated';

/**
 * Payments for an issued invoice, shown beside (never inside) the printable
 * document. Recording sends an idempotency key created when the form opens,
 * so a double click or retry records the payment once.
 */
const props = defineProps<{
    invoice: InvoiceData;
    canRecord: boolean;
    methods: { value: string; label: string }[];
    /** Today in the workspace's timezone (Y-m-d): the default and latest date received. */
    today: string;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const summary = computed(() => props.invoice.summary);
const money = (minor: number) => formatMoney(minor, summary.value.currency);
const status = computed(() => summary.value.payment_status ?? 'unpaid');

/* ---- Record payment --------------------------------------------------- */

const isRecordOpen = ref(false);
const form = useForm({ idempotency_key: '', amount: '', received_on: '', method: 'bank_transfer', reference: '', note: '' });

function openRecord() {
    form.defaults({
        idempotency_key: crypto.randomUUID(),
        amount: minorToInput(summary.value.balance_due_minor),
        received_on: props.today,
        method: 'bank_transfer',
        reference: '',
        note: '',
    });
    form.reset();
    form.clearErrors();
    isRecordOpen.value = true;
}

const typedAmount = computed(() => parseScaled(form.amount));
const tooMuch = computed(() => typedAmount.value !== null && typedAmount.value > summary.value.balance_due_minor);

function record() {
    if (form.processing) return;

    form.post(workspaceRoute('workspace.invoices.payments.store', { invoice: summary.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => (isRecordOpen.value = false),
    });
}

/* ---- Void payment -------------------------------------------------------- */

const voidTarget = ref<PaymentData | null>(null);
const voidForm = useForm({ reason: '' });

function openVoid(payment: PaymentData) {
    voidForm.reset();
    voidForm.clearErrors();
    voidTarget.value = payment;
}

function confirmVoid() {
    if (!voidTarget.value || voidForm.processing) return;

    voidForm.post(workspaceRoute('workspace.invoices.payments.void', { invoice: summary.value.public_id, payment: voidTarget.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => (voidTarget.value = null),
    });
}

const actionsFor = (payment: PaymentData): DropdownEntry[] => [
    { label: 'Void payment', icon: Ban, destructive: true, onSelect: () => openVoid(payment) },
];
</script>

<template>
    <section class="bg-card rounded-2xl border" data-testid="payments">
        <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="bg-muted flex size-10 shrink-0 items-center justify-center rounded-xl"><Wallet class="size-5" /></span>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold">Payment</h2>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="PAYMENT_STATUS_STYLES[status]"
                            data-testid="payment-status"
                        >
                            {{ summary.payment_status_label }}
                        </span>
                    </div>
                    <dl class="text-muted-foreground mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                        <div>
                            Paid
                            <span class="text-foreground font-medium tabular-nums" data-testid="paid-amount">{{ money(summary.paid_minor) }}</span>
                        </div>
                        <div>
                            Balance due
                            <span class="text-foreground font-medium tabular-nums" data-testid="balance-due">{{
                                money(summary.balance_due_minor)
                            }}</span>
                        </div>
                    </dl>
                </div>
            </div>
            <Button v-if="canRecord && summary.balance_due_minor > 0" class="gap-1.5" data-testid="record-payment" @click="openRecord">
                <Plus class="size-4" />
                Record payment
            </Button>
        </div>

        <ul v-if="invoice.payments.length" class="divide-y border-t" data-testid="payment-history">
            <li
                v-for="payment in invoice.payments"
                :key="payment.public_id"
                class="flex items-center gap-4 px-5 py-3"
                :class="payment.voided_at && 'opacity-60'"
            >
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-medium">
                        {{ formatShortDate(payment.received_on) }}, {{ payment.received_on.slice(0, 4) }} · {{ payment.method_label }}
                        <span v-if="payment.voided_at" class="bg-muted ml-1 rounded px-1.5 py-0.5 text-xs font-normal">Voided</span>
                    </p>
                    <p class="text-muted-foreground truncate text-xs">
                        <template v-if="payment.reference">Ref: {{ payment.reference }}</template>
                        <template v-if="payment.reference && (payment.note || payment.voided_at)"> · </template>
                        <template v-if="payment.voided_at">Voided by {{ payment.voided_by }}: {{ payment.void_reason }}</template>
                        <template v-else-if="payment.note">{{ payment.note }}</template>
                    </p>
                </div>
                <span class="text-sm font-medium tabular-nums" :class="payment.voided_at && 'line-through'">{{ money(payment.amount_minor) }}</span>
                <div class="w-8 shrink-0">
                    <AppDropDown v-if="canRecord && !payment.voided_at" :items="actionsFor(payment)" align="end" trigger-label="Payment actions" />
                </div>
            </li>
        </ul>
    </section>

    <AppModal
        :open="isRecordOpen"
        title="Record payment"
        :description="`Balance due ${money(summary.balance_due_minor)}`"
        size="sm"
        @update:open="(value) => !form.processing && (isRecordOpen = value)"
    >
        <form id="record-payment-form" class="space-y-4 pt-1" @submit.prevent="record">
            <div class="grid gap-1.5">
                <Label for="payment-amount" class="text-sm font-medium">Amount</Label>
                <label
                    class="focus-within:border-ring focus-within:ring-ring/30 flex h-10 items-center rounded-md border px-3 focus-within:ring-2"
                    :class="(form.errors.amount || tooMuch) && 'border-destructive'"
                >
                    <span class="text-muted-foreground text-sm">{{ summary.currency === 'USD' ? '$' : summary.currency }}</span>
                    <input
                        id="payment-amount"
                        v-model="form.amount"
                        class="w-full bg-transparent pl-1 tabular-nums outline-none"
                        inputmode="decimal"
                        data-testid="payment-amount"
                    />
                </label>
                <p v-if="tooMuch" class="text-destructive text-xs">That is more than the {{ money(summary.balance_due_minor) }} still owed.</p>
                <InputError :message="form.errors.amount" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="payment-date" class="text-sm font-medium">Date received</Label>
                    <input
                        id="payment-date"
                        v-model="form.received_on"
                        type="date"
                        :max="today"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                        data-testid="payment-date"
                    />
                    <InputError :message="form.errors.received_on" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="payment-method" class="text-sm font-medium">Method</Label>
                    <select id="payment-method" v-model="form.method" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option v-for="method in methods" :key="method.value" :value="method.value">{{ method.label }}</option>
                    </select>
                    <InputError :message="form.errors.method" />
                </div>
            </div>
            <AppFormInput
                id="payment-reference"
                v-model="form.reference"
                label="Reference"
                hint="Optional, e.g. a bank reference"
                :error="form.errors.reference"
            />
            <AppFormInput id="payment-note" v-model="form.note" label="Note" hint="Optional" :error="form.errors.note" />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="isRecordOpen = false">Cancel</Button>
            <Button
                type="submit"
                form="record-payment-form"
                :disabled="form.processing || tooMuch || typedAmount === null || typedAmount === 0"
                data-testid="confirm-payment"
            >
                <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                Record payment
            </Button>
        </template>
    </AppModal>

    <AppModal
        :open="voidTarget !== null"
        :title="voidTarget ? `Void this ${money(voidTarget.amount_minor)} payment?` : 'Void payment'"
        description="It stays in the history but no longer counts as paid. To fix a wrong amount, void it and record the correct one."
        size="sm"
        @update:open="(value) => !voidForm.processing && !value && (voidTarget = null)"
    >
        <form id="void-payment-form" class="pt-1" @submit.prevent="confirmVoid">
            <AppFormInput
                id="void-reason"
                v-model="voidForm.reason"
                label="Reason"
                placeholder="e.g. entered by mistake"
                :error="voidForm.errors.reason"
                required
            />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="voidForm.processing" @click="voidTarget = null">Cancel</Button>
            <Button type="submit" form="void-payment-form" variant="destructive" :disabled="voidForm.processing" data-testid="confirm-void">
                <Loader2 v-if="voidForm.processing" class="mr-2 size-4 animate-spin" />
                Void payment
            </Button>
        </template>
    </AppModal>
</template>
