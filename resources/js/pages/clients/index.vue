<script lang="ts">
/*
 * Browser Back makes Inertia restore this page from history with the props it
 * had at the time, which can predate a client that was just added. Remember
 * that the visit came from history so the list can be refreshed on mount.
 */
let restoredFromHistory = false;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => (restoredFromHistory = true));
}
</script>

<script setup lang="ts">
import { Building2, ChevronRight, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type Client, type CurrencyOption } from '@/lib/clients';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    clients: Client[];
    currencies: CurrencyOption[];
    canManageClients: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clients', href: workspaceRoute('workspace.clients.index') }];

const isCreateModalOpen = ref(false);

const active = computed(() => props.clients.filter((client) => !client.archived_at));
const archived = computed(() => props.clients.filter((client) => client.archived_at));

const filter = ref<'active' | 'archived'>('active');

const filterOptions = computed(() => [
    { value: 'active', label: 'Active', count: active.value.length },
    { value: 'archived', label: 'Archived', count: archived.value.length },
]);

/* Falls back to the active list if the last archived client was just restored. */
const visible = computed(() => (filter.value === 'archived' && archived.value.length ? archived.value : active.value));

onMounted(() => {
    if (restoredFromHistory) {
        restoredFromHistory = false;
        router.reload({ only: ['clients'] });
    }
});
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader eyebrow="Money" title="Clients" description="The businesses you send invoices to.">
                <template v-if="canManageClients && clients.length" #actions>
                    <Button size="sm" class="gap-1.5" @click="isCreateModalOpen = true">
                        <Plus class="size-3.5" />
                        New client
                    </Button>
                </template>
            </AppPageHeader>

            <AppSegmentedControl v-if="archived.length" v-model="filter" :options="filterOptions" class="self-start" />

            <div class="bg-card overflow-hidden rounded-xl border shadow-sm">
                <AppEmptyState
                    v-if="!clients.length"
                    title="Add your first client"
                    description="A client is a business you send invoices to. You only need a name, an email and a currency."
                >
                    <template #icon>
                        <Building2 class="size-5" />
                    </template>
                    <template v-if="canManageClients" #actions>
                        <Button class="gap-1.5" @click="isCreateModalOpen = true">
                            <Plus class="size-4" />
                            New client
                        </Button>
                    </template>
                </AppEmptyState>

                <AppEmptyState
                    v-else-if="!visible.length"
                    compact
                    title="No active clients"
                    description="Every client is archived. Open one from the Archived tab to restore it."
                />

                <ul v-else class="divide-y" data-testid="client-list">
                    <!-- The whole row is one real link: click, chevron, Enter, middle-click and "open in new tab" all just work. -->
                    <li v-for="client in visible" :key="client.public_id">
                        <Link
                            :href="workspaceRoute('workspace.clients.show', { client: client.public_id })"
                            class="group hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:ring-ring flex cursor-pointer items-center gap-4 px-4 py-4 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset"
                        >
                            <ClientAvatar :name="client.name" :logo-url="client.logo_url" />
                            <div class="min-w-0 flex-1">
                                <p class="text-foreground truncate font-semibold">{{ client.name }}</p>
                                <p class="text-muted-foreground truncate text-sm">{{ client.billing_email }} · {{ client.currency }}</p>
                            </div>
                            <ChevronRight
                                class="text-muted-foreground group-hover:text-foreground size-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                            />
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>

    <ClientFormModal v-if="canManageClients" v-model:open="isCreateModalOpen" :currencies="currencies" />
</template>
