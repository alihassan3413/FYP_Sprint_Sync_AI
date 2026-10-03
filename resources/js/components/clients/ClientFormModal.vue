<script setup lang="ts">
import { ChevronRight, Loader2 } from 'lucide-vue-next';

import type { Client, CurrencyOption } from '@/lib/clients';

const props = defineProps<{
    open: boolean;
    currencies: CurrencyOption[];
    /** Present when editing; absent when adding a new client. */
    client?: Client | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const isEditing = computed(() => !!props.client);

const form = useForm({
    name: '',
    billing_email: '',
    currency: 'USD',
    cc_emails: '',
    address: '',
    tax_id: '',
});

const showDetails = ref(false);

function fill() {
    const client = props.client;

    form.defaults({
        name: client?.name ?? '',
        billing_email: client?.billing_email ?? '',
        currency: client?.currency ?? 'USD',
        cc_emails: client?.cc_emails.join(', ') ?? '',
        address: client?.address ?? '',
        tax_id: client?.tax_id ?? '',
    });
    form.reset();
    form.clearErrors();

    showDetails.value = !!(client && (client.cc_emails.length || client.address || client.tax_id));
}

watch(
    () => props.open,
    (open) => open && fill(),
    { immediate: true },
);

/* Validation errors inside the collapsed section must not stay hidden. */
watch(
    () => form.errors,
    (errors) => {
        if (Object.keys(errors).some((key) => key.startsWith('cc_emails') || key === 'address' || key === 'tax_id')) {
            showDetails.value = true;
        }
    },
);

const ccError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.cc_emails ?? Object.entries(errors).find(([key]) => key.startsWith('cc_emails.'))?.[1];
});

const canSubmit = computed(() => form.name.trim().length >= 2 && form.billing_email.trim() !== '' && !form.processing);

function submit() {
    if (!canSubmit.value) return;

    const options = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.client) {
        form.put(workspaceRoute('workspace.clients.update', { client: props.client.public_id }), options);
    } else {
        form.post(workspaceRoute('workspace.clients.store'), options);
    }
}

function handleClose(value: boolean) {
    if (form.processing) return;

    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        :title="isEditing ? 'Edit client' : 'New client'"
        :description="isEditing ? 'Changes apply to future invoices only.' : 'Who are you invoicing?'"
        size="md"
        @update:open="handleClose"
    >
        <form id="client-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="client-name"
                v-model="form.name"
                label="Business name"
                placeholder="e.g. RocketFlood"
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <AppFormInput
                id="client-billing-email"
                v-model="form.billing_email"
                type="email"
                label="Send invoices to"
                placeholder="billing@company.com"
                :error="form.errors.billing_email"
                required
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="client-currency" class="text-sm font-medium">Currency</Label>
                <select
                    id="client-currency"
                    v-model="form.currency"
                    class="border-input bg-background focus:ring-ring/40 h-10 rounded-md border px-3 text-sm focus:ring-2 focus:outline-none"
                    :aria-invalid="!!form.errors.currency"
                >
                    <option v-for="currency in currencies" :key="currency.value" :value="currency.value">
                        {{ currency.label }}
                    </option>
                </select>
                <InputError :message="form.errors.currency" />
            </div>

            <div>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm transition-colors"
                    :aria-expanded="showDetails"
                    @click="showDetails = !showDetails"
                >
                    <ChevronRight class="size-3.5 transition-transform" :class="showDetails && 'rotate-90'" />
                    More details <span class="text-xs">(optional)</span>
                </button>

                <div v-if="showDetails" class="mt-4 space-y-5">
                    <AppFormInput
                        id="client-cc-emails"
                        v-model="form.cc_emails"
                        label="Also send copies to"
                        placeholder="finance@company.com, ceo@company.com"
                        hint="Separate addresses with commas."
                        :error="ccError"
                        autocomplete="off"
                    />

                    <div class="grid gap-1.5">
                        <Label for="client-address" class="text-sm font-medium">Billing address</Label>
                        <Textarea
                            id="client-address"
                            v-model="form.address"
                            rows="3"
                            placeholder="Street, city, country"
                            :aria-invalid="!!form.errors.address"
                        />
                        <InputError :message="form.errors.address" />
                    </div>

                    <AppFormInput
                        id="client-tax-id"
                        v-model="form.tax_id"
                        label="Tax ID"
                        placeholder="e.g. VAT or NTN number"
                        :error="form.errors.tax_id"
                        autocomplete="off"
                    />
                </div>
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="submit" form="client-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Saving…' : isEditing ? 'Save changes' : 'Add client' }}
            </Button>
        </template>
    </AppModal>
</template>
