<script setup lang="ts">
import { CalendarPlus, ChevronLeft, Loader2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatDuration, requestKey } from '@/lib/time';
import { type BreadcrumbItem } from '@/types';

type ProjectOption = { public_id: string; name: string; tasks: { public_id: string; title: string }[] };

const props = defineProps<{
    people: { public_id: string; name: string; title: string | null }[];
    projects: ProjectOption[];
    today: string;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Time', href: workspaceRoute('workspace.time.index') },
    { title: 'Add previous hours', href: workspaceRoute('workspace.time.previous-hours.create') },
];

const form = useForm({
    period_start: `${props.today.slice(0, 8)}01`,
    period_end: props.today,
    project: props.projects[0]?.public_id ?? '',
    task: '',
    reason: 'Before SprintSync tracking started',
    billable: true,
    rows: props.people.map((person) => ({ person: person.public_id, hours: '' })),
    /* One key for this form: saving twice (double click, retry) saves once. */
    idempotency_key: requestKey(),
});

const tasks = computed(() => props.projects.find((project) => project.public_id === form.project)?.tasks ?? []);
watch(
    () => form.project,
    () => (form.task = ''),
);

/** Live total, in whole hundredths of an hour (no floats in the sum). */
const totalSeconds = computed(() =>
    form.rows.reduce((sum, row) => {
        const match = /^(\d{1,5})(?:\.(\d{1,2}))?$/.exec(row.hours.trim());

        return match ? sum + (Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))) * 36 : sum;
    }, 0),
);

const filled = computed(() => form.rows.filter((row) => row.hours.trim() !== '').length);

function save() {
    form.post(workspaceRoute('workspace.time.previous-hours.store'), { preserveScroll: true });
}

/** Errors come back per filled row (rows.0.hours); map them to the person shown. */
function rowError(index: number): string | undefined {
    const filledIndex = form.rows.slice(0, index + 1).filter((row) => row.hours.trim() !== '').length - 1;
    const errors = form.errors as Record<string, string>;

    return form.rows[index].hours.trim() === '' ? undefined : (errors[`rows.${filledIndex}.hours`] ?? errors[`rows.${filledIndex}.person`]);
}
</script>

<template>
    <Head title="Add previous hours" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <Link
                :href="workspaceRoute('workspace.time.index')"
                class="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 text-sm transition-colors"
            >
                <ChevronLeft class="size-3.5" />
                Time
            </Link>
            <AppPageHeader
                eyebrow="Time"
                title="Add previous hours"
                description="Totals for a period before tracking started. They're saved as totals for the period, not as made-up shifts."
            />

            <form class="bg-card space-y-6 rounded-3xl border p-5 sm:p-6" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppFormInput id="period_start" v-model="form.period_start" type="date" label="From" :error="form.errors.period_start" required />
                    <AppFormInput id="period_end" v-model="form.period_end" type="date" label="To" :error="form.errors.period_end" required />
                    <div class="grid gap-1.5">
                        <Label for="project">Project</Label>
                        <select id="project" v-model="form.project" class="bg-background h-10 rounded-md border px-3 text-sm" data-testid="project">
                            <option v-for="project in projects" :key="project.public_id" :value="project.public_id">{{ project.name }}</option>
                        </select>
                        <InputError :message="form.errors.project" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="task">Task <span class="text-muted-foreground font-normal">(optional)</span></Label>
                        <select id="task" v-model="form.task" class="bg-background h-10 rounded-md border px-3 text-sm">
                            <option value="">No task</option>
                            <option v-for="task in tasks" :key="task.public_id" :value="task.public_id">{{ task.title }}</option>
                        </select>
                    </div>
                    <AppFormInput id="reason" v-model="form.reason" class="sm:col-span-2" label="Reason" :error="form.errors.reason" required />
                    <label class="inline-flex items-center gap-2 text-sm sm:col-span-2">
                        <AppSwitch id="billable" v-model="form.billable" label="Billable" />
                        Billable
                    </label>
                </div>

                <section>
                    <div class="mb-2 flex items-end justify-between">
                        <h2 class="text-sm font-semibold">Hours per person</h2>
                        <p class="text-muted-foreground text-xs">Leave blank to skip someone</p>
                    </div>
                    <ul class="divide-y rounded-xl border" data-testid="rows">
                        <li v-for="(row, index) in form.rows" :key="row.person" class="flex items-center gap-3 px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ people[index].name }}</p>
                                <p v-if="people[index].title" class="text-muted-foreground truncate text-xs">{{ people[index].title }}</p>
                                <InputError :message="rowError(index)" />
                            </div>
                            <input
                                v-model="row.hours"
                                inputmode="decimal"
                                placeholder="0.00"
                                class="bg-background h-9 w-24 rounded-md border px-2 text-right text-sm tabular-nums"
                                :aria-label="`Hours for ${people[index].name}`"
                                :data-testid="`hours-${index}`"
                            />
                        </li>
                    </ul>
                    <InputError class="mt-2" :message="form.errors.rows" />
                </section>

                <div class="flex flex-col gap-3 border-t pt-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-muted-foreground text-sm" data-testid="bulk-total">
                        {{ filled }} {{ filled === 1 ? 'person' : 'people' }} · {{ formatDuration(totalSeconds) }} in total
                    </p>
                    <Button type="submit" class="gap-1.5" :disabled="form.processing || filled === 0" data-testid="save-hours">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        <CalendarPlus v-else class="size-4" />
                        Save hours
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
