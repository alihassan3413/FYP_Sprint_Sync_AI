<script setup lang="ts">
import { usePoll } from '@inertiajs/vue3';
import { CheckCircle2, ChevronLeft, Download, Loader2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type Row = {
    row_number: number;
    name: string | null;
    email: string | null;
    role: string | null;
    department: string | null;
    status: 'ready' | 'warning' | 'skipped' | 'error';
    outcome: 'invited' | 'linked' | 'skipped' | 'failed' | null;
    outcome_label: string | null;
    messages: string[];
};

const props = defineProps<{
    teamImport: {
        public_id: string;
        original_name: string;
        status: 'validated' | 'queued' | 'processing' | 'completed' | 'cancelled' | 'expired';
        total_rows: number;
        processed_rows: number;
        importing_rows: number;
        created_by: string | null;
        confirmed_by: string | null;
        created_at: string;
        completed_at: string | null;
    };
    counts: Record<string, number>;
    rows: Row[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Team', href: workspaceRoute('workspace.people.index') },
    { title: 'Import teammates', href: workspaceRoute('workspace.people.imports.create') },
];

const running = computed(() => props.teamImport.status === 'queued' || props.teamImport.status === 'processing');
const { start, stop } = usePoll(2000, { only: ['teamImport', 'counts', 'rows'] }, { autoStart: false });
watch(running, (isRunning) => (isRunning ? start() : stop()), { immediate: true });

const progress = computed(() =>
    props.teamImport.importing_rows ? Math.round((props.teamImport.processed_rows / props.teamImport.importing_rows) * 100) : 100,
);

const filter = ref<string>('all');
const filterOptions = computed(() => [
    { value: 'all', label: 'All', count: props.rows.length },
    { value: 'ready', label: 'Ready', count: props.counts.ready ?? 0 },
    { value: 'warning', label: 'Warnings', count: props.counts.warning ?? 0 },
    { value: 'skipped', label: 'Skipped', count: props.counts.skipped ?? 0 },
    { value: 'error', label: 'Errors', count: props.counts.error ?? 0 },
]);
const visible = computed(() => (filter.value === 'all' ? props.rows : props.rows.filter((row) => row.status === filter.value)));

const STATUS_STYLES: Record<Row['status'], string> = {
    ready: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    skipped: 'bg-muted text-muted-foreground',
    error: 'bg-red-500/10 text-red-700 dark:text-red-400',
};
const STATUS_LABELS: Record<Row['status'], string> = { ready: 'Ready', warning: 'Warning', skipped: 'Skipped', error: 'Error' };

const confirming = ref(false);
function confirmImport() {
    if (confirming.value) return;
    confirming.value = true;
    router.post(
        workspaceRoute('workspace.people.imports.confirm', { teamImport: props.teamImport.public_id }),
        {},
        { preserveScroll: true, onFinish: () => (confirming.value = false) },
    );
}

function cancelImport() {
    router.post(workspaceRoute('workspace.people.imports.cancel', { teamImport: props.teamImport.public_id }));
}

const issuesUrl = computed(() => workspaceRoute('workspace.people.imports.errors', { teamImport: props.teamImport.public_id }));
const hasIssues = computed(
    () => (props.counts.error ?? 0) + (props.counts.skipped ?? 0) + (props.counts.warning ?? 0) + (props.counts.outcome_failed ?? 0) > 0,
);
</script>

<template>
    <Head title="Import teammates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <Link
                :href="workspaceRoute('workspace.people.imports.create')"
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" />
                Import teammates
            </Link>
            <AppPageHeader
                eyebrow="Team import"
                :title="teamImport.original_name"
                :description="`${teamImport.total_rows} rows${teamImport.created_by ? ` · uploaded by ${teamImport.created_by}` : ''}`"
            >
                <template v-if="hasIssues" #actions>
                    <Button as-child size="sm" variant="outline" class="gap-1.5">
                        <a :href="issuesUrl" data-testid="download-issues"><Download class="size-3.5" /> Download issue report</a>
                    </Button>
                </template>
            </AppPageHeader>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="summary">
                <div class="bg-card rounded-2xl border p-4">
                    <p class="text-muted-foreground text-xs">Ready</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.ready ?? 0 }}</p>
                </div>
                <div class="bg-card rounded-2xl border p-4">
                    <p class="text-muted-foreground text-xs">Warnings (will import)</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.warning ?? 0 }}</p>
                </div>
                <div class="bg-card rounded-2xl border p-4">
                    <p class="text-muted-foreground text-xs">Skipped</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ counts.skipped ?? 0 }}</p>
                </div>
                <div class="bg-card rounded-2xl border p-4">
                    <p class="text-muted-foreground text-xs">Errors (not imported)</p>
                    <p class="text-2xl font-semibold tabular-nums" :class="counts.error && 'text-destructive'">{{ counts.error ?? 0 }}</p>
                </div>
            </div>

            <div
                v-if="teamImport.status === 'validated'"
                class="bg-card flex flex-col gap-3 rounded-2xl border p-5 sm:flex-row sm:items-center sm:justify-between"
                data-testid="confirm-panel"
            >
                <p class="text-sm">
                    <template v-if="teamImport.importing_rows">
                        <span class="font-semibold">{{ teamImport.importing_rows }}</span>
                        {{ teamImport.importing_rows === 1 ? 'person' : 'people' }} will be invited. Skipped and error rows are left out; fix them in
                        your file and import again.
                    </template>
                    <template v-else>Nothing in this file can be imported. Fix the errors and upload it again.</template>
                </p>
                <div class="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" data-testid="cancel-import" @click="cancelImport">Cancel</Button>
                    <Button size="sm" :disabled="!teamImport.importing_rows || confirming" data-testid="confirm-import" @click="confirmImport">
                        <Loader2 v-if="confirming" class="mr-2 size-4 animate-spin" />
                        Import {{ teamImport.importing_rows }} {{ teamImport.importing_rows === 1 ? 'teammate' : 'teammates' }}
                    </Button>
                </div>
            </div>

            <div v-else-if="running" class="bg-card space-y-2 rounded-2xl border p-5" data-testid="progress">
                <p class="flex items-center gap-2 text-sm font-medium">
                    <Loader2 class="size-4 animate-spin" /> Importing… {{ teamImport.processed_rows }} of {{ teamImport.importing_rows }}
                </p>
                <div class="bg-muted h-2 overflow-hidden rounded-full">
                    <div class="bg-primary h-full rounded-full transition-[width] duration-500" :style="{ width: `${progress}%` }"></div>
                </div>
            </div>

            <div v-else-if="teamImport.status === 'completed'" class="bg-card space-y-1 rounded-2xl border p-5" data-testid="result">
                <p class="flex items-center gap-2 font-semibold"><CheckCircle2 class="size-4 text-emerald-600" /> Import finished</p>
                <p class="text-muted-foreground text-sm">
                    {{ counts.outcome_invited ?? 0 }} invited · {{ counts.outcome_linked ?? 0 }} linked to existing members ·
                    {{ (counts.outcome_skipped ?? 0) + (counts.skipped ?? 0) }} skipped · {{ counts.outcome_failed ?? 0 }} failed ·
                    {{ counts.error ?? 0 }} with errors
                </p>
            </div>

            <div v-else class="bg-muted/60 text-muted-foreground rounded-2xl px-5 py-4 text-sm" data-testid="closed">
                This import was {{ teamImport.status }}. Nothing was imported.
            </div>

            <section>
                <AppSegmentedControl v-model="filter" :options="filterOptions" class="mb-3" />
                <div class="bg-card overflow-x-auto rounded-2xl border">
                    <table class="w-full text-sm" data-testid="rows">
                        <thead class="text-muted-foreground text-left text-xs">
                            <tr class="border-b">
                                <th class="px-4 py-2 font-medium">Row</th>
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Email</th>
                                <th class="px-4 py-2 font-medium">Role</th>
                                <th class="px-4 py-2 font-medium">Department</th>
                                <th class="px-4 py-2 font-medium">Status</th>
                                <th class="px-4 py-2 font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in visible" :key="row.row_number" class="align-top">
                                <td class="text-muted-foreground px-4 py-2 tabular-nums">{{ row.row_number }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ row.name ?? '—' }}</td>
                                <td class="px-4 py-2">{{ row.email ?? '—' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">{{ row.role ?? '—' }}</td>
                                <td class="px-4 py-2">{{ row.department ?? '—' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_STYLES[row.status]">{{
                                        STATUS_LABELS[row.status]
                                    }}</span>
                                    <span
                                        v-if="row.outcome_label"
                                        class="text-muted-foreground ml-1 text-xs"
                                        :class="row.outcome === 'failed' && 'text-destructive'"
                                        >· {{ row.outcome_label }}</span
                                    >
                                </td>
                                <td class="text-muted-foreground px-4 py-2 text-xs">
                                    <p v-for="message in row.messages" :key="message">{{ message }}</p>
                                </td>
                            </tr>
                            <tr v-if="!visible.length">
                                <td colspan="7" class="text-muted-foreground px-4 py-6 text-center">No rows here.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
