<script setup lang="ts">
import { Archive, ArchiveRestore, ChevronLeft, ChevronRight, Coins, ImageMinus, ImageUp, Loader2, Mail, Pencil, Plus, Repeat } from 'lucide-vue-next';

import type { DropdownEntry } from '@/components/ui/AppDropDown.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { TeamMemberOption } from '@/lib/billing';
import { type Client, type CurrencyOption } from '@/lib/clients';
import { type BreadcrumbItem } from '@/types';
import type { BillingPlanData } from '@/types/generated';

const props = defineProps<{
    client: Client;
    currencies: CurrencyOption[];
    canManageClients: boolean;
    plans: BillingPlanData[];
    /** Today in the workspace's timezone (Y-m-d). */
    today: string;
    /** Team profiles that can go on a recurring invoice; only sent to people who can manage it. */
    teamMembers?: TeamMemberOption[];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Clients', href: workspaceRoute('workspace.clients.index') },
    { title: props.client.name, href: workspaceRoute('workspace.clients.show', { client: props.client.public_id }) },
]);

const isEditModalOpen = ref(false);

/* ---- Recurring invoices ------------------------------------------------- */

/* Archived clients keep their recurring invoices visible but read-only. */
const canManagePlans = computed(() => props.canManageClients && !props.client.archived_at);
const isPlanSheetOpen = ref(false);
const editingPlan = ref<BillingPlanData | null>(null);

function openPlan(plan: BillingPlanData | null) {
    if (!canManagePlans.value) return;

    editingPlan.value = plan;
    isPlanSheetOpen.value = true;
}

function setPaused(plan: BillingPlanData, paused: boolean) {
    router.post(
        workspaceRoute(paused ? 'workspace.clients.plans.pause' : 'workspace.clients.plans.resume', {
            client: props.client.public_id,
            billingPlan: plan.public_id,
        }),
        {},
        { preserveScroll: true },
    );
}
const showDetails = ref(false);

const hasDetails = computed(() => props.client.cc_emails.length > 0 || !!props.client.address || !!props.client.tax_id);

function archive() {
    router.post(workspaceRoute('workspace.clients.archive', { client: props.client.public_id }));
}

function restore() {
    router.post(workspaceRoute('workspace.clients.restore', { client: props.client.public_id }), {}, { preserveScroll: true });
}

/* Logo: pick a file and it uploads straight away. The server validates the actual image. */
const logoInput = ref<HTMLInputElement | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });

function chooseLogo() {
    logoForm.clearErrors();
    logoInput.value?.click();
}

function uploadLogo(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    logoForm.logo = file;
    logoForm.post(workspaceRoute('workspace.clients.logo.store', { client: props.client.public_id }), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            input.value = '';
            logoForm.logo = null;
        },
    });
}

function removeLogo() {
    router.delete(workspaceRoute('workspace.clients.logo.destroy', { client: props.client.public_id }), { preserveScroll: true });
}

/* Archiving is reversible from this page, so it doesn't ask for confirmation. */
const actions = computed<DropdownEntry[]>(() =>
    props.canManageClients
        ? [
              { label: 'Edit details', icon: Pencil, onSelect: () => (isEditModalOpen.value = true) },
              { label: props.client.logo_url ? 'Change logo' : 'Upload logo', icon: ImageUp, onSelect: chooseLogo },
              ...(props.client.logo_url ? [{ label: 'Remove logo', icon: ImageMinus, onSelect: removeLogo }] : []),
              props.client.archived_at
                  ? { label: 'Restore client', icon: ArchiveRestore, onSelect: restore }
                  : { label: 'Archive client', icon: Archive, onSelect: archive },
          ]
        : [],
);
</script>

<template>
    <Head :title="client.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-6xl flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8">
            <div class="flex flex-col gap-5">
                <Link
                    :href="workspaceRoute('workspace.clients.index')"
                    class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm transition-colors"
                >
                    <ChevronLeft class="size-3.5" />
                    Clients
                </Link>

                <div
                    v-if="client.archived_at"
                    class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 sm:flex-row sm:items-center"
                >
                    <Archive class="size-4 shrink-0 text-amber-700" />
                    <p class="flex-1">This client is archived. It's hidden from your client list, and nothing has been deleted.</p>
                    <Button v-if="canManageClients" variant="outline" size="sm" class="gap-1.5 bg-white" @click="restore">
                        <ArchiveRestore class="size-3.5" />
                        Restore
                    </Button>
                </div>

                <div class="flex items-start gap-4">
                    <ClientAvatar :name="client.name" :logo-url="client.logo_url" size="lg" />

                    <div class="min-w-0 flex-1">
                        <h1 class="text-foreground text-2xl font-semibold tracking-tight sm:text-[28px]">{{ client.name }}</h1>
                        <div class="text-muted-foreground mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <p class="min-w-0">
                                <Mail class="mr-1.5 inline size-3.5 align-[-2px]" />
                                Invoices go to
                                <span
                                    class="text-foreground inline-block max-w-full truncate align-bottom font-medium"
                                    data-testid="client-billing-email"
                                    >{{ client.billing_email }}</span
                                >
                            </p>
                            <span class="inline-flex items-center gap-1.5">
                                <Coins class="size-3.5" />
                                {{ client.currency }}
                            </span>
                        </div>
                        <p v-if="logoForm.processing" class="text-muted-foreground mt-2 inline-flex items-center gap-1.5 text-xs">
                            <Loader2 class="size-3.5 animate-spin" />
                            Uploading logo…
                        </p>
                        <InputError class="mt-2" :message="logoForm.errors.logo" />
                    </div>

                    <AppDropDown
                        v-if="actions.length"
                        :items="actions"
                        :heading="`Manage ${client.name}`"
                        align="end"
                        trigger-label="Open client actions"
                    />
                </div>
            </div>

            <section data-testid="recurring-invoices">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="text-muted-foreground text-[11px] font-semibold tracking-[0.12em] uppercase">Recurring invoices</h2>
                    <Button v-if="canManagePlans && plans.length" variant="outline" size="sm" class="gap-1.5" @click="openPlan(null)">
                        <Plus class="size-3.5" />
                        Recurring invoice
                    </Button>
                </div>

                <div v-if="plans.length" class="grid gap-4 lg:grid-cols-2">
                    <RecurringInvoiceCard
                        v-for="plan in plans"
                        :key="plan.public_id"
                        :plan="plan"
                        :can-manage="canManagePlans"
                        @edit="openPlan(plan)"
                        @pause="setPaused(plan, true)"
                        @resume="setPaused(plan, false)"
                    />
                </div>
                <div v-else class="bg-card rounded-2xl border border-dashed">
                    <AppEmptyState
                        compact
                        title="No recurring invoice yet"
                        :description="
                            canManagePlans
                                ? 'Set it up once. SprintSync prepares it every month.'
                                : `Recurring invoices for ${client.name} will appear here.`
                        "
                    >
                        <template #icon>
                            <Repeat class="size-5" />
                        </template>
                        <template v-if="canManagePlans" #actions>
                            <Button size="sm" class="gap-1.5" @click="openPlan(null)">
                                <Plus class="size-3.5" />
                                Set up a recurring invoice
                            </Button>
                        </template>
                    </AppEmptyState>
                </div>
            </section>

            <section class="bg-card rounded-xl border">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium"
                    :aria-expanded="showDetails"
                    @click="showDetails = !showDetails"
                >
                    <ChevronRight class="size-4 transition-transform" :class="showDetails && 'rotate-90'" />
                    More details
                </button>

                <div v-if="showDetails" class="grid gap-4 border-t px-4 py-4 text-sm sm:grid-cols-3">
                    <div>
                        <p class="text-muted-foreground text-xs">Billing address</p>
                        <p class="whitespace-pre-line">{{ client.address || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Also send copies to</p>
                        <p class="break-words">{{ client.cc_emails.length ? client.cc_emails.join(', ') : '—' }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground text-xs">Tax ID</p>
                        <p>{{ client.tax_id || '—' }}</p>
                    </div>
                    <p v-if="!hasDetails && canManageClients" class="text-muted-foreground text-xs sm:col-span-3">
                        <button type="button" class="text-primary-text font-medium hover:underline" @click="isEditModalOpen = true">
                            Add details
                        </button>
                        such as an address or extra recipients.
                    </p>
                </div>
            </section>
        </div>
    </AppLayout>

    <ClientFormModal v-if="canManageClients" v-model:open="isEditModalOpen" :currencies="currencies" :client="client" />
    <RecurringInvoiceSheet
        v-if="canManagePlans"
        v-model:open="isPlanSheetOpen"
        :client="client"
        :plan="editingPlan"
        :team-members="teamMembers ?? []"
        :currencies="currencies"
        :today="today"
    />
    <input
        v-if="canManageClients"
        ref="logoInput"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        class="hidden"
        data-testid="client-logo-input"
        @change="uploadLogo"
    />
</template>
