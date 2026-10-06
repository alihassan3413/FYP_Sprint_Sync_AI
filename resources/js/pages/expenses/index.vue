<script setup lang="ts">
import { Ban, Loader2, Pencil, Plus, Receipt, Repeat, Square } from 'lucide-vue-next';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ordinal } from '@/lib/billing';
import { type BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };
type Named = { public_id: string; name: string };
type ExpenseRow = {
    public_id: string;
    category: string;
    description: string;
    vendor: string | null;
    amount: string;
    currency: string;
    expense_date: string;
    client: string | null;
    project: string | null;
    recurring: boolean;
    notes: string | null;
    voided: boolean;
    void_reason: string | null;
};
type RecurringRow = {
    public_id: string;
    category: string;
    category_label: string;
    description: string;
    vendor: string | null;
    amount: string;
    amount_formatted: string;
    currency: string;
    day_of_month: number;
    starts_on: string;
    ends_on: string | null;
    client: string | null;
    client_name: string | null;
    project: string | null;
    project_name: string | null;
    notes: string | null;
    stopped: boolean;
};

const props = defineProps<{
    period: { key: string; from: string; to: string };
    expenses: ExpenseRow[];
    /** Per currency; amounts in different currencies are never added together. */
    totals: { currency: string; amount: string }[];
    recurring: RecurringRow[];
    categories: Option[];
    currencies: Option[];
    clients: Named[];
    projects: Named[];
    today: string;
    canManage: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expenses', href: workspaceRoute('workspace.expenses.index') }];

/* ---- Add / edit -------------------------------------------------------- */

const isFormOpen = ref(false);
const editing = ref<RecurringRow | null>(null);
const repeat = ref<'once' | 'monthly'>('once');
const form = useForm({
    idempotency_key: '',
    category: 'software',
    description: '',
    vendor: '',
    amount: '',
    currency: 'USD',
    expense_date: '',
    day_of_month: 1,
    starts_on: '',
    ends_on: '',
    client: '',
    project: '',
    notes: '',
});

function openForm(recurring: RecurringRow | null = null) {
    editing.value = recurring;
    repeat.value = recurring ? 'monthly' : 'once';
    form.defaults({
        idempotency_key: crypto.randomUUID(),
        category: recurring?.category ?? 'software',
        description: recurring?.description ?? '',
        vendor: recurring?.vendor ?? '',
        amount: recurring?.amount ?? '',
        currency: recurring?.currency ?? 'USD',
        expense_date: props.today,
        day_of_month: recurring?.day_of_month ?? 1,
        starts_on: recurring?.starts_on ?? props.today.slice(0, 8) + '01',
        ends_on: recurring?.ends_on ?? '',
        client: recurring?.client ?? '',
        project: recurring?.project ?? '',
        notes: recurring?.notes ?? '',
    });
    form.reset();
    form.clearErrors();
    isFormOpen.value = true;
}

function save() {
    if (form.processing) return;

    const options = { preserveScroll: true, onSuccess: () => (isFormOpen.value = false) };
    if (editing.value) {
        form.put(workspaceRoute('workspace.expenses.recurring.update', { recurringExpense: editing.value.public_id }), options);
    } else if (repeat.value === 'monthly') {
        form.post(workspaceRoute('workspace.expenses.recurring.store'), options);
    } else {
        form.post(workspaceRoute('workspace.expenses.store'), options);
    }
}

/* ---- Void / stop ------------------------------------------------------- */

const voidTarget = ref<ExpenseRow | null>(null);
const voidForm = useForm({ reason: '' });

function confirmVoid() {
    if (!voidTarget.value || voidForm.processing) return;

    voidForm.post(workspaceRoute('workspace.expenses.void', { expense: voidTarget.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => (voidTarget.value = null),
    });
}

const expenseActions = (expense: ExpenseRow): DropdownEntry[] => [
    {
        label: 'Void expense',
        icon: Ban,
        destructive: true,
        onSelect: () => {
            voidForm.reset();
            voidForm.clearErrors();
            voidTarget.value = expense;
        },
    },
];

const recurringActions = (recurring: RecurringRow): DropdownEntry[] => [
    { label: 'Edit', icon: Pencil, onSelect: () => openForm(recurring) },
    {
        label: 'Stop',
        icon: Square,
        destructive: true,
        onSelect: () =>
            router.post(workspaceRoute('workspace.expenses.recurring.stop', { recurringExpense: recurring.public_id }), {}, { preserveScroll: true }),
    },
];
</script>

<template>
    <Head title="Expenses" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Money" title="Expenses" description="Money going out, each in the currency it was paid in.">
                <template v-if="canManage" #actions>
                    <Button size="sm" class="gap-1.5" data-testid="add-expense" @click="openForm()">
                        <Plus class="size-3.5" />
                        Add expense
                    </Button>
                </template>
            </AppPageHeader>

            <FinancePeriodPicker :period="period" />

            <div class="bg-card rounded-2xl border p-5" data-testid="expense-totals">
                <p class="text-muted-foreground text-sm">Total for this period</p>
                <p v-if="!totals.length" class="mt-1 text-2xl font-semibold tabular-nums">None</p>
                <p v-for="total in totals" :key="total.currency" class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                    {{ total.amount }} <span v-if="total.currency === 'USD'" class="text-muted-foreground text-sm font-normal">USD</span>
                </p>
                <p v-if="totals.length > 1" class="text-muted-foreground mt-2 text-xs">
                    Shown per currency. See the Finance overview for a combined total.
                </p>
            </div>

            <section>
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Expenses</h2>
                <div class="bg-card overflow-hidden rounded-2xl border">
                    <AppEmptyState
                        v-if="!expenses.length"
                        compact
                        title="No expenses in this period"
                        description="Add one, or set up a monthly expense like rent or software."
                    >
                        <template #icon><Receipt class="size-5" /></template>
                    </AppEmptyState>
                    <ul v-else class="divide-y" data-testid="expense-list">
                        <li
                            v-for="expense in expenses"
                            :key="expense.public_id"
                            class="flex items-center gap-4 px-4 py-3 sm:px-5"
                            :class="expense.voided && 'opacity-60'"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">
                                    {{ expense.description }}
                                    <Repeat v-if="expense.recurring" class="text-muted-foreground ml-1 inline size-3.5" aria-label="Monthly" />
                                    <span v-if="expense.voided" class="bg-muted ml-1 rounded px-1.5 py-0.5 text-xs font-normal">Voided</span>
                                </p>
                                <p class="text-muted-foreground truncate text-sm">
                                    {{ expense.expense_date }} · {{ expense.category }}
                                    <template v-if="expense.vendor"> · {{ expense.vendor }}</template>
                                    <template v-if="expense.client"> · {{ expense.client }}</template>
                                    <template v-if="expense.project"> · {{ expense.project }}</template>
                                    <template v-if="expense.voided"> · Voided: {{ expense.void_reason }}</template>
                                </p>
                            </div>
                            <span class="text-sm font-medium tabular-nums" :class="expense.voided && 'line-through'">
                                {{ expense.amount }} <span v-if="expense.currency === 'USD'" class="text-muted-foreground text-xs">USD</span>
                            </span>
                            <div class="w-8 shrink-0">
                                <AppDropDown
                                    v-if="canManage && !expense.voided"
                                    :items="expenseActions(expense)"
                                    align="end"
                                    trigger-label="Expense actions"
                                />
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-if="recurring.length" data-testid="recurring-list">
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Monthly expenses</h2>
                <ul class="bg-card divide-y overflow-hidden rounded-2xl border">
                    <li
                        v-for="item in recurring"
                        :key="item.public_id"
                        class="flex items-center gap-4 px-4 py-3 sm:px-5"
                        :class="item.stopped && 'opacity-60'"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">
                                {{ item.description }}
                                <span v-if="item.stopped" class="bg-muted ml-1 rounded px-1.5 py-0.5 text-xs font-normal">Stopped</span>
                            </p>
                            <p class="text-muted-foreground truncate text-sm">
                                {{ item.category_label }} · on the {{ ordinal(item.day_of_month) }} from {{ item.starts_on }}
                                <template v-if="item.ends_on"> until {{ item.ends_on }}</template>
                            </p>
                        </div>
                        <span class="text-sm font-medium tabular-nums">{{ item.amount_formatted }}</span>
                        <div class="w-8 shrink-0">
                            <AppDropDown
                                v-if="canManage && !item.stopped"
                                :items="recurringActions(item)"
                                align="end"
                                trigger-label="Monthly expense actions"
                            />
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>

    <AppModal
        :open="isFormOpen"
        :title="editing ? 'Edit monthly expense' : 'Add expense'"
        :description="editing ? 'Months already recorded keep their amount. Changes apply from the next month.' : undefined"
        @update:open="(value) => !form.processing && (isFormOpen = value)"
    >
        <form id="expense-form" class="space-y-4 pt-1" @submit.prevent="save">
            <AppSegmentedControl
                v-if="!editing"
                v-model="repeat"
                :options="[
                    { value: 'once', label: 'One-time' },
                    { value: 'monthly', label: 'Every month' },
                ]"
            />
            <AppFormInput
                id="expense-description"
                v-model="form.description"
                label="Description"
                placeholder="e.g. Office rent"
                :error="form.errors.description"
                required
            />
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="expense-category" class="text-sm font-medium">Category</Label>
                    <select id="expense-category" v-model="form.category" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
                    </select>
                    <InputError :message="form.errors.category" />
                </div>
                <AppFormInput id="expense-vendor" v-model="form.vendor" label="Paid to" hint="Optional" :error="form.errors.vendor" />
            </div>
            <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                <div class="grid gap-1.5">
                    <Label for="expense-currency" class="text-sm font-medium">Currency</Label>
                    <select
                        id="expense-currency"
                        v-model="form.currency"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                        data-testid="expense-currency"
                    >
                        <option v-for="currency in currencies" :key="currency.value" :value="currency.value">{{ currency.value }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="expense-amount" class="text-sm font-medium">Amount ({{ form.currency }})</Label>
                    <input
                        id="expense-amount"
                        v-model="form.amount"
                        inputmode="decimal"
                        class="bg-background h-10 rounded-md border px-3 tabular-nums"
                        data-testid="expense-amount"
                    />
                    <InputError :message="form.errors.amount" />
                </div>
            </div>
            <div v-if="repeat === 'once'" class="grid gap-1.5">
                <Label for="expense-date" class="text-sm font-medium">Date</Label>
                <input
                    id="expense-date"
                    v-model="form.expense_date"
                    type="date"
                    :max="today"
                    class="bg-background h-10 rounded-md border px-2 text-sm"
                />
                <InputError :message="form.errors.expense_date" />
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="expense-day" class="text-sm font-medium">Day of month</Label>
                    <input
                        id="expense-day"
                        v-model.number="form.day_of_month"
                        type="number"
                        min="1"
                        max="28"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                    />
                    <InputError :message="form.errors.day_of_month" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="expense-starts" class="text-sm font-medium">Starts</Label>
                    <input id="expense-starts" v-model="form.starts_on" type="date" class="bg-background h-10 rounded-md border px-2 text-sm" />
                    <InputError :message="form.errors.starts_on" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="expense-ends" class="text-sm font-medium">Ends (optional)</Label>
                    <input
                        id="expense-ends"
                        v-model="form.ends_on"
                        type="date"
                        :min="form.starts_on"
                        class="bg-background h-10 rounded-md border px-2 text-sm"
                    />
                    <InputError :message="form.errors.ends_on" />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="expense-client" class="text-sm font-medium">Client (optional)</Label>
                    <select id="expense-client" v-model="form.client" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option value="">None</option>
                        <option v-for="client in clients" :key="client.public_id" :value="client.public_id">{{ client.name }}</option>
                    </select>
                    <InputError :message="form.errors.client" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="expense-project" class="text-sm font-medium">Project (optional)</Label>
                    <select id="expense-project" v-model="form.project" class="bg-background h-10 rounded-md border px-2 text-sm">
                        <option value="">None</option>
                        <option v-for="project in projects" :key="project.public_id" :value="project.public_id">{{ project.name }}</option>
                    </select>
                    <InputError :message="form.errors.project" />
                </div>
            </div>
            <AppFormInput id="expense-notes" v-model="form.notes" label="Notes" hint="Optional" :error="form.errors.notes" />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="isFormOpen = false">Cancel</Button>
            <Button type="submit" form="expense-form" :disabled="form.processing" data-testid="save-expense">
                <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                {{ editing ? 'Save changes' : repeat === 'monthly' ? 'Save monthly expense' : 'Add expense' }}
            </Button>
        </template>
    </AppModal>

    <AppModal
        :open="voidTarget !== null"
        :title="voidTarget ? `Void ${voidTarget.amount} ${voidTarget.currency}?` : 'Void expense'"
        description="It stays in the history but no longer counts. To fix a wrong amount, void it and add the correct one."
        size="sm"
        @update:open="(value) => !voidForm.processing && !value && (voidTarget = null)"
    >
        <form id="void-expense-form" class="pt-1" @submit.prevent="confirmVoid">
            <AppFormInput
                id="void-expense-reason"
                v-model="voidForm.reason"
                label="Reason"
                placeholder="e.g. entered twice"
                :error="voidForm.errors.reason"
                required
            />
        </form>
        <template #footer>
            <Button type="button" variant="outline" :disabled="voidForm.processing" @click="voidTarget = null">Cancel</Button>
            <Button type="submit" form="void-expense-form" variant="destructive" :disabled="voidForm.processing" data-testid="confirm-void-expense">
                <Loader2 v-if="voidForm.processing" class="mr-2 size-4 animate-spin" />
                Void expense
            </Button>
        </template>
    </AppModal>
</template>
