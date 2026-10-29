<script setup lang="ts">
import { ChevronLeft, FileSpreadsheet, Loader2, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type RecentImport = { public_id: string; original_name: string; status: string; total_rows: number; created_at: string; created_by: string | null };

const props = defineProps<{
    limits: { rows: number; megabytes: number };
    columns: string[];
    recent: RecentImport[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Team', href: workspaceRoute('workspace.people.index') },
    { title: 'Import teammates', href: workspaceRoute('workspace.people.imports.create') },
];

const form = useForm<{ file: File | null }>({ file: null });
const dragging = ref(false);
const picker = ref<HTMLInputElement | null>(null);

const STATUS_LABELS: Record<string, string> = {
    validated: 'Waiting for confirmation',
    queued: 'Queued',
    processing: 'Importing',
    completed: 'Completed',
    cancelled: 'Cancelled',
    expired: 'Expired',
};

function choose(file: File | undefined | null) {
    if (!file) return;
    form.clearErrors();
    const extension = file.name.split('.').pop()?.toLowerCase();
    if (extension !== 'xlsx' && extension !== 'csv') {
        form.setError('file', 'Upload an .xlsx or .csv file.');
        return;
    }
    if (file.size > props.limits.megabytes * 1024 * 1024) {
        form.setError('file', `The file must be ${props.limits.megabytes} MB or smaller.`);
        return;
    }
    form.file = file;
}

function drop(event: DragEvent) {
    dragging.value = false;
    choose(event.dataTransfer?.files?.[0]);
}

function submit() {
    if (!form.file || form.processing) return;
    form.post(workspaceRoute('workspace.people.imports.store'), { forceFormData: true });
}
</script>

<template>
    <Head title="Import teammates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <Link
                :href="workspaceRoute('workspace.people.index')"
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
            >
                <ChevronLeft class="size-3.5" />
                Team
            </Link>
            <AppPageHeader
                eyebrow="Team"
                title="Import teammates"
                description="Upload a spreadsheet to invite several people at once. Nothing is sent until you review and confirm."
            />

            <section class="bg-card space-y-3 rounded-2xl border p-5">
                <h2 class="font-semibold">1. Start from the template</h2>
                <p class="text-muted-foreground text-sm">
                    Columns: <span class="text-foreground font-mono text-xs">{{ columns.join(', ') }}</span
                    >. Role is <span class="font-medium">admin</span>, <span class="font-medium">member</span> or
                    <span class="font-medium">client</span>, optionally with an existing custom role (<span class="font-mono text-xs"
                        >member: Quote Lead</span
                    >). Schedule is <span class="font-mono text-xs">default</span> or a teammate's email to copy their schedule. No passwords:
                    everyone gets an invitation.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child size="sm" variant="outline" class="gap-1.5">
                        <a :href="workspaceRoute('workspace.people.imports.template')" data-testid="template-xlsx"
                            ><FileSpreadsheet class="size-3.5" /> Download template (.xlsx)</a
                        >
                    </Button>
                    <Button as-child size="sm" variant="ghost" class="gap-1.5">
                        <a :href="`${workspaceRoute('workspace.people.imports.template')}?format=csv`" data-testid="template-csv">Download .csv</a>
                    </Button>
                </div>
            </section>

            <section class="bg-card space-y-3 rounded-2xl border p-5">
                <h2 class="font-semibold">2. Upload your file</h2>
                <form @submit.prevent="submit">
                    <div
                        role="button"
                        tabindex="0"
                        class="focus-visible:ring-ring flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors outline-none focus-visible:ring-2"
                        :class="dragging ? 'border-primary-text bg-accent/40' : 'border-border hover:bg-muted/40'"
                        data-testid="dropzone"
                        @click="picker?.click()"
                        @keydown.enter.prevent="picker?.click()"
                        @keydown.space.prevent="picker?.click()"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="drop"
                    >
                        <Upload class="text-muted-foreground size-6" />
                        <p class="text-sm font-medium">{{ form.file ? form.file.name : 'Drop an .xlsx or .csv file here, or click to choose' }}</p>
                        <p class="text-muted-foreground text-xs">Up to {{ limits.rows }} teammates and {{ limits.megabytes }} MB.</p>
                        <input
                            ref="picker"
                            type="file"
                            accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                            class="hidden"
                            data-testid="file-input"
                            @change="choose(($event.target as HTMLInputElement).files?.[0])"
                        />
                    </div>
                    <InputError class="mt-2" :message="form.errors.file" />
                    <div class="mt-4 flex justify-end">
                        <Button type="submit" :disabled="!form.file || form.processing" data-testid="upload">
                            <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                            Check file
                        </Button>
                    </div>
                </form>
            </section>

            <section v-if="recent.length" data-testid="recent-imports">
                <h2 class="text-muted-foreground mb-3 text-[11px] font-semibold tracking-[0.12em] uppercase">Recent imports</h2>
                <ul class="bg-card divide-y overflow-hidden rounded-2xl border">
                    <li v-for="item in recent" :key="item.public_id">
                        <Link
                            :href="workspaceRoute('workspace.people.imports.show', { teamImport: item.public_id })"
                            class="hover:bg-muted/40 flex items-center justify-between gap-4 px-5 py-3 text-sm"
                        >
                            <span class="min-w-0 truncate font-medium">{{ item.original_name }}</span>
                            <span class="text-muted-foreground shrink-0 text-xs">
                                {{ item.total_rows }} rows · {{ STATUS_LABELS[item.status] ?? item.status
                                }}<template v-if="item.created_by"> · {{ item.created_by }}</template>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
