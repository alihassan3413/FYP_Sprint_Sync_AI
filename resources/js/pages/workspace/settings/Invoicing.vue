<script setup lang="ts">
import { ImageUp, Loader2, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type Details = {
    business_name: string;
    legal_name: string;
    billing_email: string;
    phone: string;
    address_line1: string;
    address_line2: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    tax_id: string;
};

const props = defineProps<{
    details: Partial<Record<keyof Details, string | null>> | null;
    /** Authorised URL to the current logo; null when there is none. */
    logoUrl: string | null;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: workspaceRoute('workspace.settings') },
    { title: 'Invoicing', href: workspaceRoute('workspace.invoicing.edit') },
];

const fields: (keyof Details)[] = [
    'business_name',
    'legal_name',
    'billing_email',
    'phone',
    'address_line1',
    'address_line2',
    'city',
    'region',
    'postal_code',
    'country',
    'tax_id',
];

const form = useForm<Details>(Object.fromEntries(fields.map((field) => [field, props.details?.[field] ?? ''])) as Details);

function save() {
    form.put(workspaceRoute('workspace.invoicing.update'), { preserveScroll: true });
}

/* ---- Logo: uploads as soon as a file is picked; the server checks and re-encodes it ---- */

const logoInput = ref<HTMLInputElement | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });

function uploadLogo(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    logoForm.logo = file;
    logoForm.post(workspaceRoute('workspace.invoicing.logo.store'), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            input.value = '';
            logoForm.logo = null;
        },
    });
}

function removeLogo() {
    router.delete(workspaceRoute('workspace.invoicing.logo.destroy'), { preserveScroll: true });
}

const hasSavedDetails = computed(() => !!props.details?.business_name);

/** The sender block as it will print on an invoice. */
const preview = computed(() => ({
    name: form.business_name || 'Your business name',
    lines: [
        [form.address_line1, form.address_line2].filter(Boolean).join(', '),
        [form.city, form.region, form.postal_code].filter(Boolean).join(', '),
        form.country,
        form.billing_email,
        form.tax_id ? `Tax ID ${form.tax_id}` : '',
    ].filter(Boolean),
}));
</script>

<template>
    <Head title="Invoicing" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Settings" title="Invoicing" description="These details appear on your invoices as the sender." />

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <form class="bg-card space-y-8 rounded-3xl border p-5 sm:p-6" @submit.prevent="save">
                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold">Business details</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppFormInput
                                id="business_name"
                                v-model="form.business_name"
                                label="Business name"
                                :error="form.errors.business_name"
                                required
                            />
                            <AppFormInput
                                id="legal_name"
                                v-model="form.legal_name"
                                label="Legal name"
                                hint="Optional"
                                :error="form.errors.legal_name"
                            />
                            <AppFormInput
                                id="billing_email"
                                v-model="form.billing_email"
                                type="email"
                                label="Billing email"
                                :error="form.errors.billing_email"
                                required
                            />
                            <AppFormInput id="phone" v-model="form.phone" label="Phone" hint="Optional" :error="form.errors.phone" />
                        </div>
                    </section>

                    <section class="space-y-4">
                        <h2 class="text-sm font-semibold">Address</h2>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppFormInput
                                id="address_line1"
                                v-model="form.address_line1"
                                class="sm:col-span-2"
                                label="Address line 1"
                                :error="form.errors.address_line1"
                                required
                            />
                            <AppFormInput
                                id="address_line2"
                                v-model="form.address_line2"
                                class="sm:col-span-2"
                                label="Address line 2"
                                hint="Optional"
                                :error="form.errors.address_line2"
                            />
                            <AppFormInput id="city" v-model="form.city" label="City" :error="form.errors.city" required />
                            <AppFormInput id="region" v-model="form.region" label="State / region" hint="Optional" :error="form.errors.region" />
                            <AppFormInput
                                id="postal_code"
                                v-model="form.postal_code"
                                label="Postal code"
                                hint="Optional"
                                :error="form.errors.postal_code"
                            />
                            <AppFormInput id="country" v-model="form.country" label="Country" :error="form.errors.country" required />
                            <AppFormInput id="tax_id" v-model="form.tax_id" label="Tax ID / VAT number" hint="Optional" :error="form.errors.tax_id" />
                        </div>
                    </section>

                    <div class="flex items-center justify-end gap-3 border-t pt-5">
                        <p v-if="form.recentlySuccessful" class="text-muted-foreground text-sm">Saved.</p>
                        <Button type="submit" :disabled="form.processing" data-testid="save-invoicing">
                            <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                            Save
                        </Button>
                    </div>
                </form>

                <aside class="space-y-6">
                    <section class="bg-card rounded-3xl border p-5 sm:p-6">
                        <h2 class="text-sm font-semibold">Logo</h2>
                        <p class="text-muted-foreground mt-1 text-xs">
                            PNG, JPEG or WebP, up to 2 MB. Invoices already prepared keep the logo they had.
                        </p>

                        <div class="bg-muted/40 mt-4 flex h-28 items-center justify-center rounded-xl border border-dashed p-3">
                            <img
                                v-if="logoUrl"
                                :src="logoUrl"
                                alt="Invoice logo"
                                class="max-h-full max-w-full object-contain"
                                data-testid="logo-preview"
                            />
                            <span v-else class="text-muted-foreground text-sm">No logo</span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="gap-1.5"
                                :disabled="!hasSavedDetails || logoForm.processing"
                                @click="logoInput?.click()"
                            >
                                <Loader2 v-if="logoForm.processing" class="size-3.5 animate-spin" />
                                <ImageUp v-else class="size-3.5" />
                                {{ logoUrl ? 'Replace logo' : 'Upload logo' }}
                            </Button>
                            <Button v-if="logoUrl" type="button" variant="ghost" size="sm" class="text-destructive gap-1.5" @click="removeLogo">
                                <Trash2 class="size-3.5" />
                                Remove
                            </Button>
                        </div>
                        <p v-if="!hasSavedDetails" class="text-muted-foreground mt-2 text-xs">Save your business details first.</p>
                        <InputError class="mt-2" :message="logoForm.errors.logo" />
                        <input
                            ref="logoInput"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="hidden"
                            data-testid="logo-input"
                            @change="uploadLogo"
                        />
                    </section>

                    <section class="bg-card rounded-3xl border p-5 sm:p-6">
                        <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.12em] uppercase">On your invoices</h2>
                        <div class="mt-4 space-y-0.5 text-sm" data-testid="sender-preview">
                            <img v-if="logoUrl" :src="logoUrl" alt="" class="mb-3 max-h-10 max-w-[160px] object-contain" />
                            <p class="font-semibold">{{ preview.name }}</p>
                            <p v-for="line in preview.lines" :key="line" class="text-muted-foreground">{{ line }}</p>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
