<script setup lang="ts">
import { Bell, CalendarDays, Clock, Eye, Info, Loader2, Package, Repeat, ShieldCheck, UserRound, X, Zap } from 'lucide-vue-next';

import {
    basisPointsToInput,
    calculateTotals,
    formatMoney,
    formatShortDate,
    generationDayFor,
    minorToInput,
    monthName,
    nextCycle,
    ordinal,
    parseScaled,
    REMINDER_OPTIONS,
    type AdjustmentKind,
    type AdjustmentType,
    type PricingMode,
    type TeamMemberOption,
} from '@/lib/billing';
import type { CurrencyOption } from '@/lib/clients';
import type { BillingPlanData } from '@/types/generated';

/**
 * The recurring invoice builder: a wide sheet where the invoice itself is the
 * editor. Amounts stay as typed strings and are sent as strings; the server
 * converts them to integers and recalculates every total.
 */
const props = defineProps<{
    open: boolean;
    client: { public_id: string; name: string; billing_email: string; currency: string };
    /** Present when editing an existing recurring invoice. */
    plan: BillingPlanData | null;
    teamMembers: TeamMemberOption[];
    currencies: CurrencyOption[];
    /** Today in the workspace's timezone (Y-m-d). */
    today: string;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { workspaceRoute } = useCurrentWorkspace();

type LineForm = {
    key: number;
    person: string | null;
    description: string;
    role_label: string | null;
    unit_price: string;
};

type AdjustmentForm = {
    kind: AdjustmentKind;
    type: AdjustmentType;
    label: string;
    value: string;
};

const form = useForm<{
    name: string;
    currency: string;
    pricing_mode: PricingMode | null;
    send_day: number;
    billing_period: 'previous_month' | 'current_month';
    due_in_days: number | string;
    delivery_mode: 'review_before_sending' | 'auto_send';
    reminder_days_before: number;
    lines: LineForm[];
    adjustments: AdjustmentForm[];
}>({
    name: '',
    currency: 'USD',
    pricing_mode: null,
    send_day: 1,
    billing_period: 'previous_month',
    due_in_days: 15,
    delivery_mode: 'review_before_sending',
    reminder_days_before: 3,
    lines: [],
    adjustments: [],
});

let nextKey = 0;

const isEditing = computed(() => props.plan !== null);
const isHourly = computed(() => form.pricing_mode === 'hourly');

function fill() {
    const plan = props.plan;

    form.defaults({
        name: plan?.name ?? '',
        currency: plan?.currency ?? props.client.currency,
        pricing_mode: plan?.pricing_mode ?? null,
        send_day: plan?.send_day ?? 1,
        billing_period: plan?.billing_period ?? 'previous_month',
        due_in_days: plan?.due_in_days ?? 15,
        delivery_mode: plan?.delivery_mode ?? 'review_before_sending',
        reminder_days_before: plan?.reminder_days_before ?? 3,
        lines: (plan?.lines ?? []).map((line) => ({
            key: nextKey++,
            person: line.person_public_id ?? null,
            description: line.description,
            role_label: line.role_label ?? null,
            unit_price: minorToInput(line.unit_price_minor),
        })),
        adjustments: (plan?.adjustments ?? []).map((adjustment) => ({
            kind: adjustment.kind,
            type: adjustment.type,
            label: adjustment.label,
            value: adjustment.type === 'percentage' ? basisPointsToInput(adjustment.value) : minorToInput(adjustment.value),
        })),
    });
    form.reset();
    form.clearErrors();
    adjustmentDraft.value = null;
}

watch(
    () => props.open,
    (open) => open && fill(),
    { immediate: true },
);

/* ---- How do you bill this? --------------------------------------------- */

function chooseMode(mode: PricingMode) {
    if (form.pricing_mode === mode) return;

    const hadMode = form.pricing_mode !== null;
    form.pricing_mode = mode;

    // Hourly drafts are prepared on the 1st and go out on the 3rd, leaving two days to check hours.
    form.send_day = mode === 'hourly' ? 3 : 1;
    form.billing_period = 'previous_month';

    if (hadMode) {
        form.lines = form.lines.filter((line) => mode === 'fixed' || line.person !== null).map((line) => ({ ...line, unit_price: '' }));
    }
}

/* ---- Lines ------------------------------------------------------------- */

const adder = ref<{ focus: () => void } | null>(null);
const amountInputs = new Map<number, HTMLInputElement>();

const usedPersonIds = computed(() => form.lines.map((line) => line.person).filter((id): id is string => id !== null));

async function addLine(line: { person: string | null; description: string; role_label: string | null }) {
    const key = nextKey++;
    form.lines.push({ key, ...line, unit_price: '' });

    await nextTick();
    amountInputs.get(key)?.focus();
}

/** "2000" → "2000.00" once the field is left; anything invalid stays as typed for the error to show. */
function tidyAmount(line: LineForm) {
    const minor = parseScaled(line.unit_price);

    if (minor !== null) line.unit_price = minorToInput(minor);
}

function removeLine(index: number) {
    form.lines.splice(index, 1);
}

function lineError(index: number): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return errors[`lines.${index}.unit_price`] ?? errors[`lines.${index}.person`] ?? errors[`lines.${index}.description`];
}

/* ---- Fees, discounts and taxes ----------------------------------------- */

const adjustmentDraft = ref<AdjustmentForm | null>(null);

const adjustmentError = ref<string | null>(null);
const adjustmentAmountInput = ref<HTMLInputElement | null>(null);

async function startAdjustment() {
    adjustmentDraft.value = { kind: 'fee', type: 'percentage', label: '', value: '' };
    adjustmentError.value = null;

    await nextTick();
    adjustmentAmountInput.value?.focus();
}

/** Why the draft can't be added yet, in plain words; null when it can. */
function adjustmentProblem(draft: AdjustmentForm): string | null {
    const scaled = parseScaled(draft.value);

    if (draft.value.trim() === '') return draft.type === 'percentage' ? 'Enter the percentage, for example 2.' : 'Enter the amount, for example 50.';
    if (scaled === null) return draft.type === 'percentage' ? 'Use a number like 2 or 2.5.' : 'Use an amount like 50 or 49.99.';
    if (scaled === 0) return 'It has to be more than zero.';
    if (draft.type === 'percentage' && scaled > 10_000) return 'A percentage can’t be more than 100.';

    return null;
}

/** "2% of $4,960.00 = $99.20" while typing a percentage on a fixed invoice. */
const adjustmentPreview = computed(() => {
    const draft = adjustmentDraft.value;

    if (!draft || isHourly.value || draft.type !== 'percentage' || adjustmentProblem(draft)) return null;

    const amount = calculateTotals([totals.value.subtotal], [{ kind: 'fee', type: 'percentage', value: parseScaled(draft.value) ?? 0 }])
        .adjustments[0];

    return `${draft.value.trim()}% of ${money(totals.value.subtotal)} = ${money(amount)}`;
});

function addAdjustment() {
    const draft = adjustmentDraft.value;

    if (!draft) return;

    adjustmentError.value = adjustmentProblem(draft);

    if (adjustmentError.value) {
        adjustmentAmountInput.value?.focus();

        return;
    }

    const label = draft.label.trim() || adjustmentKinds.find((kind) => kind.value === draft.kind)!.label;

    form.adjustments.push({ ...draft, value: draft.value.trim(), label });
    adjustmentDraft.value = null;
}

const adjustmentKinds: { value: AdjustmentKind; label: string }[] = [
    { value: 'fee', label: 'Fee' },
    { value: 'discount', label: 'Discount' },
    { value: 'tax', label: 'Tax' },
];

function adjustmentDescription(adjustment: AdjustmentForm): string {
    return adjustment.type === 'percentage' ? `${adjustment.value}% of subtotal` : 'fixed amount';
}

/* ---- Live preview (the server recalculates on save) --------------------- */

const totals = computed(() =>
    calculateTotals(
        form.lines.map((line) => parseScaled(line.unit_price) ?? 0),
        form.adjustments.map((adjustment) => ({ kind: adjustment.kind, type: adjustment.type, value: parseScaled(adjustment.value) ?? 0 })),
    ),
);

const money = (minor: number) => formatMoney(minor, form.currency);

const sentenceField =
    'border-border bg-background hover:border-foreground/30 focus:border-ring focus:ring-ring/30 mx-0.5 h-8 rounded-md border px-1.5 align-baseline text-sm font-medium focus:ring-2 focus:outline-none';

const next = computed(() =>
    nextCycle(generationDayFor(form.pricing_mode ?? 'fixed', form.send_day), form.send_day, form.billing_period, props.today),
);

/* ---- Save -------------------------------------------------------------- */

const canSave = computed(() => form.pricing_mode !== null && form.name.trim() !== '' && form.lines.length > 0 && !form.processing);

function save() {
    if (!canSave.value) return;

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };
    const payload = form.transform((data) => ({
        ...data,
        lines: data.lines.map(({ person, description, role_label, unit_price }) => ({ person, description, role_label, unit_price })),
    }));

    if (props.plan) {
        payload.put(workspaceRoute('workspace.clients.plans.update', { client: props.client.public_id, billingPlan: props.plan.public_id }), options);
    } else {
        payload.post(workspaceRoute('workspace.clients.plans.store', { client: props.client.public_id }), options);
    }
}

function close(value: boolean) {
    if (!form.processing) emit('update:open', value);
}
</script>

<template>
    <Sheet :open="open" @update:open="close">
        <SheetContent side="right" class="flex w-full flex-col gap-0 p-0 sm:max-w-3xl" @open-auto-focus.prevent>
            <header class="bg-card flex h-16 shrink-0 items-center border-b px-5 pr-12">
                <div class="min-w-0">
                    <p class="text-muted-foreground truncate text-[11px] font-medium tracking-[0.08em] uppercase">
                        {{ client.name }} · Recurring invoice
                    </p>
                    <SheetTitle class="truncate text-base">{{ plan ? plan.name : 'New recurring invoice' }}</SheetTitle>
                </div>
                <SheetDescription class="sr-only">Set up what to bill {{ client.name }} every month, and how it is sent.</SheetDescription>
            </header>

            <form id="recurring-invoice-form" class="flex-1 space-y-7 overflow-y-auto p-5 sm:p-7" @submit.prevent="save">
                <p
                    v-if="isEditing"
                    class="flex items-start gap-2 rounded-lg bg-sky-500/10 px-3 py-2.5 text-sm text-sky-900 dark:text-sky-200"
                    data-testid="future-only"
                >
                    <Info class="mt-0.5 size-4 shrink-0" />
                    Changes apply to future invoices only. Existing invoices won't change.
                </p>

                <!-- Question 1 -->
                <fieldset v-if="!isEditing">
                    <legend class="mb-2.5 text-sm font-semibold">How do you bill this?</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="option in [
                                {
                                    mode: 'fixed' as const,
                                    icon: Repeat,
                                    title: 'Fixed amounts',
                                    body: 'Same price each month. Salaries, rent, retainers.',
                                },
                                {
                                    mode: 'hourly' as const,
                                    icon: Clock,
                                    title: 'Hours × rate',
                                    body: 'Enter or import hours each month. Support staff, contractors.',
                                },
                            ]"
                            :key="option.mode"
                            type="button"
                            class="flex items-start gap-3 rounded-xl border-2 p-3.5 text-left transition-colors"
                            :class="form.pricing_mode === option.mode ? 'border-foreground bg-card' : 'bg-muted/50 hover:bg-muted border-transparent'"
                            :aria-pressed="form.pricing_mode === option.mode"
                            :data-testid="`mode-${option.mode}`"
                            @click="chooseMode(option.mode)"
                        >
                            <span
                                class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-2"
                                :class="form.pricing_mode === option.mode ? 'border-foreground' : 'border-muted-foreground/40'"
                            >
                                <span v-if="form.pricing_mode === option.mode" class="bg-foreground size-2 rounded-full" />
                            </span>
                            <span>
                                <span class="flex items-center gap-1.5 text-sm font-semibold"
                                    ><component :is="option.icon" class="size-3.5" />{{ option.title }}</span
                                >
                                <span class="text-muted-foreground mt-0.5 block text-xs">{{ option.body }}</span>
                            </span>
                        </button>
                    </div>
                </fieldset>
                <p v-else class="text-muted-foreground inline-flex items-center gap-1.5 text-sm">
                    <component :is="isHourly ? Clock : Repeat" class="size-3.5" />
                    {{ isHourly ? 'Hours × rate' : 'Fixed amounts' }}
                </p>

                <template v-if="form.pricing_mode">
                    <AppFormInput
                        id="plan-name"
                        v-model="form.name"
                        label="Name"
                        placeholder="e.g. Dev & Office"
                        :error="form.errors.name"
                        required
                        autocomplete="off"
                    />

                    <!-- Schedule, as a sentence -->
                    <div>
                        <p v-if="isHourly" class="text-[15px] leading-9" data-testid="schedule-sentence">
                            Bill <span class="font-medium">last month's hours</span>. Hours are prepared from the 1st. Scheduled to send on the
                            <select v-model.number="form.send_day" :class="sentenceField" aria-label="Day it is sent">
                                <option v-for="day in 28" :key="day" :value="day">{{ ordinal(day) }}</option></select
                            >. Due in
                            <input
                                v-model="form.due_in_days"
                                :class="[sentenceField, 'w-14 text-center tabular-nums']"
                                inputmode="numeric"
                                aria-label="Days until due"
                            />
                            days.
                        </p>
                        <p v-else class="text-[15px] leading-9" data-testid="schedule-sentence">
                            On the
                            <select v-model.number="form.send_day" :class="sentenceField" aria-label="Day of the month">
                                <option v-for="day in 28" :key="day" :value="day">{{ ordinal(day) }}</option>
                            </select>
                            of each month, bill
                            <select v-model="form.billing_period" :class="sentenceField" aria-label="Which month">
                                <option value="previous_month">last month</option>
                                <option value="current_month">this month</option></select
                            >. Due in
                            <input
                                v-model="form.due_in_days"
                                :class="[sentenceField, 'w-14 text-center tabular-nums']"
                                inputmode="numeric"
                                aria-label="Days until due"
                            />
                            days.
                        </p>
                        <InputError :message="form.errors.send_day || form.errors.due_in_days" />
                    </div>

                    <!-- Delivery -->
                    <fieldset>
                        <legend class="mb-2.5 text-sm font-semibold">How should it be sent?</legend>
                        <div class="bg-muted/40 divide-y overflow-hidden rounded-xl border">
                            <div :class="form.delivery_mode === 'review_before_sending' && 'bg-card'">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-4 py-3 text-left"
                                    :aria-pressed="form.delivery_mode === 'review_before_sending'"
                                    data-testid="delivery-review"
                                    @click="form.delivery_mode = 'review_before_sending'"
                                >
                                    <span
                                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-2"
                                        :class="form.delivery_mode === 'review_before_sending' ? 'border-foreground' : 'border-muted-foreground/40'"
                                    >
                                        <span v-if="form.delivery_mode === 'review_before_sending'" class="bg-foreground size-2 rounded-full" />
                                    </span>
                                    <span>
                                        <span class="flex items-center gap-1.5 text-sm font-semibold"
                                            ><Eye class="size-3.5" />Review before sending</span
                                        >
                                        <span class="text-muted-foreground block text-xs">We'll remind you before it goes out.</span>
                                    </span>
                                </button>
                                <div v-if="form.delivery_mode === 'review_before_sending'" class="pr-4 pb-3 pl-11">
                                    <p v-if="isHourly" class="text-muted-foreground flex items-center gap-1.5 text-xs" data-testid="hourly-reminder">
                                        <Bell class="size-3.5" />
                                        We'll ask you on the 1st when last month's hours are ready.
                                    </p>
                                    <label v-else class="inline-flex flex-wrap items-center gap-2 text-sm">
                                        <Bell class="text-muted-foreground size-3.5" />
                                        Remind me
                                        <select
                                            v-model.number="form.reminder_days_before"
                                            class="bg-background h-8 rounded-md border px-2 text-sm"
                                            data-testid="reminder-select"
                                        >
                                            <option v-for="option in REMINDER_OPTIONS" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </label>
                                    <InputError :message="form.errors.reminder_days_before" />
                                </div>
                            </div>
                            <div :class="form.delivery_mode === 'auto_send' && 'bg-card'">
                                <button
                                    type="button"
                                    class="flex w-full items-start gap-3 px-4 py-3 text-left"
                                    :aria-pressed="form.delivery_mode === 'auto_send'"
                                    data-testid="delivery-auto"
                                    @click="form.delivery_mode = 'auto_send'"
                                >
                                    <span
                                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-2"
                                        :class="form.delivery_mode === 'auto_send' ? 'border-foreground' : 'border-muted-foreground/40'"
                                    >
                                        <span v-if="form.delivery_mode === 'auto_send'" class="bg-foreground size-2 rounded-full" />
                                    </span>
                                    <span>
                                        <span class="flex items-center gap-1.5 text-sm font-semibold"
                                            ><Zap class="size-3.5" />Send automatically</span
                                        >
                                        <span class="text-muted-foreground block text-xs">SprintSync sends it when everything looks right.</span>
                                    </span>
                                </button>
                                <p
                                    v-if="form.delivery_mode === 'auto_send'"
                                    class="text-muted-foreground flex items-center gap-1.5 pr-4 pb-3 pl-11 text-xs"
                                >
                                    <ShieldCheck class="size-3.5" />
                                    We'll pause and ask you if something looks unusual.
                                </p>
                            </div>
                        </div>
                    </fieldset>

                    <!-- The invoice is the editor -->
                    <div class="bg-card rounded-xl border p-4 shadow-xs sm:p-6">
                        <div class="flex items-start justify-between gap-3 border-b pb-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ client.name }}</p>
                                <p class="text-muted-foreground truncate text-xs">{{ client.billing_email }}</p>
                            </div>
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-[0.2em] uppercase">Invoice</p>
                        </div>

                        <div class="text-muted-foreground mt-3 flex justify-between pb-1 text-[11px] font-semibold tracking-wider uppercase">
                            <span class="pl-9">{{ isHourly ? 'Team member' : 'Item' }}</span>
                            <span class="pr-9">{{ isHourly ? 'Rate' : 'Amount' }}</span>
                        </div>

                        <ul data-testid="plan-lines">
                            <li v-for="(line, index) in form.lines" :key="line.key" class="border-b border-dashed py-1.5">
                                <div class="group flex items-center gap-2">
                                    <span
                                        class="flex size-7 shrink-0 items-center justify-center rounded-full"
                                        :class="line.person ? 'bg-accent' : 'bg-muted'"
                                        :title="line.person ? 'Team member' : 'Item'"
                                    >
                                        <UserRound v-if="line.person" class="size-3.5" />
                                        <Package v-else class="size-3.5" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <input
                                            v-model="line.description"
                                            class="focus:bg-muted/50 -my-0.5 w-full rounded px-1 font-medium outline-none"
                                            :aria-label="`Name on the invoice for line ${index + 1}`"
                                        />
                                        <input
                                            v-model="line.role_label"
                                            class="text-muted-foreground focus:bg-muted/50 -my-0.5 w-full rounded px-1 text-xs outline-none placeholder:opacity-0 group-hover:placeholder:opacity-100 focus:placeholder:opacity-100"
                                            :placeholder="line.person ? 'Job title on the invoice (optional)' : 'Note (optional)'"
                                            :aria-label="`Detail for line ${index + 1}`"
                                        />
                                    </div>
                                    <label
                                        class="focus-within:border-ring focus-within:ring-ring/30 hover:border-border flex w-32 shrink-0 items-center rounded-md border border-transparent px-2 transition focus-within:ring-2 sm:w-40"
                                        :class="lineError(index) && 'border-destructive'"
                                    >
                                        <span class="text-muted-foreground text-sm">{{ form.currency === 'USD' ? '$' : form.currency }}</span>
                                        <input
                                            :ref="
                                                (element) =>
                                                    element ? amountInputs.set(line.key, element as HTMLInputElement) : amountInputs.delete(line.key)
                                            "
                                            v-model="line.unit_price"
                                            class="h-9 w-full min-w-0 bg-transparent text-right font-medium tabular-nums outline-none"
                                            inputmode="decimal"
                                            placeholder="0.00"
                                            :aria-label="isHourly ? `Hourly rate for ${line.description}` : `Amount for ${line.description}`"
                                            :data-testid="`line-amount-${index}`"
                                            @keydown.enter.prevent="adder?.focus()"
                                            @blur="tidyAmount(line)"
                                        />
                                        <span v-if="isHourly" class="text-muted-foreground text-xs">/h</span>
                                    </label>
                                    <button
                                        type="button"
                                        class="text-muted-foreground hover:bg-destructive/10 hover:text-destructive flex size-7 shrink-0 items-center justify-center rounded-md transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus:opacity-100"
                                        :aria-label="`Remove ${line.description}`"
                                        @click="removeLine(index)"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </div>
                                <InputError class="pl-9" :message="lineError(index)" />
                            </li>
                        </ul>

                        <PlanLineAdder
                            ref="adder"
                            class="mt-2"
                            :team-members="teamMembers"
                            :used-person-ids="usedPersonIds"
                            :allow-items="!isHourly"
                            @add="addLine"
                        />
                        <InputError class="mt-1" :message="form.errors.lines" />

                        <!-- Totals -->
                        <div class="mt-5 ml-auto w-full space-y-1.5 border-t pt-4 text-sm sm:w-80">
                            <p v-if="isHourly" class="bg-muted/60 text-muted-foreground rounded-lg p-3 text-xs">
                                <Clock class="mr-1 inline size-3.5 align-[-2px]" />
                                Hours are added each month, typed in or from SprintSync time tracking. The total is worked out then.
                            </p>
                            <div v-else class="text-muted-foreground flex justify-between">
                                <span>Subtotal</span>
                                <span class="tabular-nums" data-testid="preview-subtotal">{{ money(totals.subtotal) }}</span>
                            </div>

                            <div
                                v-for="(adjustment, index) in form.adjustments"
                                :key="index"
                                class="group text-muted-foreground flex items-center justify-between gap-2"
                            >
                                <span class="min-w-0">
                                    {{ adjustment.label }}
                                    <span class="text-xs">({{ adjustmentDescription(adjustment) }})</span>
                                    <button
                                        type="button"
                                        class="hover:text-destructive ml-1 text-xs sm:opacity-0 sm:group-hover:opacity-100 sm:focus:opacity-100"
                                        @click="form.adjustments.splice(index, 1)"
                                    >
                                        Remove
                                    </button>
                                </span>
                                <span class="shrink-0 tabular-nums">
                                    {{
                                        isHourly
                                            ? adjustment.type === 'percentage'
                                                ? `${adjustment.kind === 'discount' ? '−' : ''}${adjustment.value}%`
                                                : money(
                                                      adjustment.kind === 'discount'
                                                          ? -(parseScaled(adjustment.value) ?? 0)
                                                          : (parseScaled(adjustment.value) ?? 0),
                                                  )
                                            : money(totals.adjustments[index])
                                    }}
                                </span>
                            </div>

                            <div v-if="adjustmentDraft" class="bg-background mt-2 space-y-3 rounded-lg border p-3" data-testid="adjustment-form">
                                <div class="bg-muted/50 inline-flex rounded-md border p-0.5 text-xs" role="group" aria-label="Type">
                                    <button
                                        v-for="kind in adjustmentKinds"
                                        :key="kind.value"
                                        type="button"
                                        class="h-7 rounded px-2.5"
                                        :class="adjustmentDraft.kind === kind.value && 'bg-card shadow-sm'"
                                        :aria-pressed="adjustmentDraft.kind === kind.value"
                                        @click="adjustmentDraft.kind = kind.value"
                                    >
                                        {{ kind.label }}
                                    </button>
                                </div>

                                <div class="grid gap-1">
                                    <span class="text-muted-foreground text-xs">Amount</span>
                                    <div class="flex items-center gap-2">
                                        <label
                                            class="focus-within:border-ring focus-within:ring-ring/30 flex h-9 w-32 items-center rounded-md border px-2 focus-within:ring-2"
                                            :class="adjustmentError && 'border-destructive'"
                                        >
                                            <span v-if="adjustmentDraft.type === 'fixed'" class="text-muted-foreground text-sm">{{
                                                form.currency === 'USD' ? '$' : form.currency
                                            }}</span>
                                            <input
                                                ref="adjustmentAmountInput"
                                                v-model="adjustmentDraft.value"
                                                class="w-full min-w-0 bg-transparent text-right text-sm tabular-nums outline-none"
                                                inputmode="decimal"
                                                :aria-label="adjustmentDraft.type === 'percentage' ? 'Percentage of subtotal' : 'Fixed amount'"
                                                :aria-invalid="!!adjustmentError"
                                                data-testid="adjustment-value"
                                                @input="adjustmentError = null"
                                                @keydown.enter.prevent="addAdjustment"
                                            />
                                            <span v-if="adjustmentDraft.type === 'percentage'" class="text-muted-foreground pl-1 text-sm">%</span>
                                        </label>
                                        <div
                                            class="bg-muted/50 inline-flex shrink-0 rounded-md border p-0.5 text-xs whitespace-nowrap"
                                            role="group"
                                            aria-label="Calculation"
                                        >
                                            <button
                                                type="button"
                                                class="h-7 rounded px-2.5"
                                                :class="adjustmentDraft.type === 'percentage' && 'bg-card shadow-sm'"
                                                :aria-pressed="adjustmentDraft.type === 'percentage'"
                                                @click="adjustmentDraft.type = 'percentage'"
                                            >
                                                Percent
                                            </button>
                                            <button
                                                type="button"
                                                class="h-7 rounded px-2.5"
                                                :class="adjustmentDraft.type === 'fixed' && 'bg-card shadow-sm'"
                                                :aria-pressed="adjustmentDraft.type === 'fixed'"
                                                @click="adjustmentDraft.type = 'fixed'"
                                            >
                                                Fixed
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="adjustmentError" class="text-destructive text-xs" role="alert" data-testid="adjustment-error">
                                        {{ adjustmentError }}
                                    </p>
                                    <p
                                        v-else-if="adjustmentPreview"
                                        class="text-muted-foreground text-xs tabular-nums"
                                        data-testid="adjustment-preview"
                                    >
                                        {{ adjustmentPreview }}
                                    </p>
                                </div>

                                <label class="grid gap-1">
                                    <span class="text-muted-foreground text-xs">Name on invoice <span class="opacity-70">(optional)</span></span>
                                    <input
                                        v-model="adjustmentDraft.label"
                                        class="focus:border-ring h-9 rounded-md border px-2 text-sm outline-none"
                                        :placeholder="
                                            adjustmentDraft.kind === 'fee'
                                                ? 'e.g. Deel fee'
                                                : adjustmentDraft.kind === 'tax'
                                                  ? 'e.g. Sales tax'
                                                  : 'e.g. Loyalty discount'
                                        "
                                        data-testid="adjustment-label"
                                        @keydown.enter.prevent="addAdjustment"
                                    />
                                </label>

                                <div class="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" size="sm" @click="adjustmentDraft = null">Cancel</Button>
                                    <Button type="button" size="sm" data-testid="adjustment-add" @click="addAdjustment">Add</Button>
                                </div>
                            </div>
                            <button
                                v-else
                                type="button"
                                class="text-primary-text text-sm font-medium hover:underline"
                                data-testid="add-adjustment"
                                @click="startAdjustment"
                            >
                                + Add fee, discount or tax
                            </button>
                            <InputError :message="form.errors.adjustments" />

                            <div v-if="!isHourly" class="flex items-baseline justify-between border-t pt-2">
                                <span class="font-semibold">Every month</span>
                                <span class="text-xl font-semibold tracking-tight tabular-nums" data-testid="preview-total">{{
                                    money(totals.total)
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <details class="bg-card group rounded-xl border">
                        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-sm font-medium">
                            More options <span class="text-muted-foreground font-normal">· currency</span>
                        </summary>
                        <div class="border-t p-4 text-sm">
                            <label class="block max-w-xs">
                                <span class="text-muted-foreground text-xs">Currency</span>
                                <select v-model="form.currency" class="bg-background mt-1 h-9 w-full rounded-md border px-2">
                                    <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.label }}</option>
                                </select>
                            </label>
                        </div>
                    </details>
                </template>
            </form>

            <footer class="bg-card flex flex-col gap-3 border-t px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p v-if="form.pricing_mode" class="text-muted-foreground text-xs" data-testid="next-invoice">
                    <CalendarDays class="mr-1 inline size-3.5 align-[-2px]" />
                    <template v-if="isHourly">
                        {{ monthName(next.periodStart) }} hours prepared {{ formatShortDate(next.generationOn) }}, sends
                        {{ formatShortDate(next.sendOn) }} ·
                    </template>
                    <template v-else>Next invoice {{ formatShortDate(next.sendOn) }}, for {{ monthName(next.periodStart) }} ·</template>
                    {{ form.delivery_mode === 'auto_send' ? 'sends automatically' : 'you review first' }}
                </p>
                <span v-else />
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="ghost" :disabled="form.processing" @click="close(false)">Cancel</Button>
                    <Button type="submit" form="recurring-invoice-form" :disabled="!canSave" data-testid="save-plan">
                        <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                        {{ form.processing ? 'Saving…' : 'Save recurring invoice' }}
                    </Button>
                </div>
            </footer>
        </SheetContent>
    </Sheet>
</template>
