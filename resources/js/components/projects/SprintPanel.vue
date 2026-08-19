<script setup lang="ts">
/**
 * Sprint management for a project: plan a sprint, run it, close it.
 *
 * The panel is organised around the lifecycle rather than the calendar — the
 * running sprint gets the detail (pace, health, burndown, what to do next),
 * everything else is a compact row.
 */
import { CalendarRange, CheckCircle2, Loader2, Play, Plus, Trash2, TriangleAlert } from 'lucide-vue-next';

import {
    formatSprintRange,
    sprintHealthStyles,
    sprintStatusStyles,
    sprintTimingLabel,
    type Sprint,
    type SprintCarryOver,
    type SprintReport,
} from '@/lib/sprints';

const props = defineProps<{
    projectId: number;
    sprints: Sprint[];
    canManage: boolean;
    activeSprintReport?: SprintReport | null;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const isCreating = ref(false);
const editingId = ref<number | null>(null);
const deletingId = ref<number | null>(null);
const runningId = ref<number | null>(null);
const completingSprint = ref<Sprint | null>(null);
const carryOver = ref<SprintCarryOver>('backlog');

const form = useForm({
    name: '',
    goal: '',
    starts_on: '',
    ends_on: '',
});

const activeSprint = computed(() => props.sprints.find((sprint) => sprint.status === 'active') ?? null);
const plannedSprints = computed(() => props.sprints.filter((sprint) => sprint.status === 'planned'));
const otherSprints = computed(() => props.sprints.filter((sprint) => sprint.status !== 'active'));
const report = computed(() => props.activeSprintReport ?? null);

const hasNextPlannedSprint = computed(() => plannedSprints.value.length > 0);

function startCreate() {
    form.clearErrors();
    form.reset();

    /* Default to a fortnight starting today — the shape most teams use. */
    const today = new Date();
    const end = new Date(today);
    end.setDate(end.getDate() + 13);

    form.starts_on = today.toISOString().slice(0, 10);
    form.ends_on = end.toISOString().slice(0, 10);

    editingId.value = null;
    isCreating.value = true;
}

function startEdit(sprint: Sprint) {
    form.clearErrors();
    form.name = sprint.name;
    form.goal = sprint.goal ?? '';
    form.starts_on = sprint.starts_on;
    form.ends_on = sprint.ends_on;
    editingId.value = sprint.id;
    isCreating.value = false;
}

function cancel() {
    form.clearErrors();
    form.reset();
    isCreating.value = false;
    editingId.value = null;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => cancel(),
    };

    if (editingId.value !== null) {
        form.put(workspaceRoute('workspace.projects.sprints.update', { project: props.projectId, sprint: editingId.value }), options);
        return;
    }

    form.post(workspaceRoute('workspace.projects.sprints.store', { project: props.projectId }), options);
}

function start(sprint: Sprint) {
    runningId.value = sprint.id;

    router.post(
        workspaceRoute('workspace.projects.sprints.start', { project: props.projectId, sprint: sprint.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => (runningId.value = null),
        },
    );
}

function askToComplete(sprint: Sprint) {
    carryOver.value = hasNextPlannedSprint.value ? 'next_sprint' : 'backlog';
    completingSprint.value = sprint;
}

function confirmComplete() {
    const sprint = completingSprint.value;

    if (sprint === null) return;

    runningId.value = sprint.id;

    router.post(
        workspaceRoute('workspace.projects.sprints.complete', { project: props.projectId, sprint: sprint.id }),
        { carry_over: carryOver.value },
        {
            preserveScroll: true,
            onSuccess: () => (completingSprint.value = null),
            onFinish: () => (runningId.value = null),
        },
    );
}

function destroy(sprint: Sprint) {
    deletingId.value = sprint.id;

    router.delete(workspaceRoute('workspace.projects.sprints.destroy', { project: props.projectId, sprint: sprint.id }), {
        preserveScroll: true,
        onFinish: () => (deletingId.value = null),
    });
}

const isFormOpen = computed(() => isCreating.value || editingId.value !== null);

const openTaskCount = computed(() =>
    completingSprint.value === null ? 0 : completingSprint.value.task_count - completingSprint.value.completed_task_count,
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Running sprint -->
        <div v-if="activeSprint" class="bg-card rounded-xl border shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 p-5 pb-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-[15px] font-semibold tracking-tight">{{ activeSprint.name }}</h3>

                        <span
                            class="rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                            :class="sprintStatusStyles[activeSprint.status]"
                        >
                            {{ activeSprint.status_label }}
                        </span>

                        <span
                            v-if="report"
                            class="rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                            :class="sprintHealthStyles[report.health]"
                        >
                            {{ report.health_label }}
                        </span>
                    </div>

                    <p class="text-muted-foreground mt-1 text-[11px]">
                        {{ formatSprintRange(activeSprint) }} · {{ sprintTimingLabel(activeSprint) }}
                    </p>

                    <p v-if="activeSprint.goal" class="mt-2 text-xs leading-relaxed">{{ activeSprint.goal }}</p>
                </div>

                <Button
                    v-if="canManage"
                    size="sm"
                    class="shrink-0 gap-1.5"
                    :disabled="runningId === activeSprint.id"
                    @click="askToComplete(activeSprint)"
                >
                    <Loader2 v-if="runningId === activeSprint.id" class="size-3.5 animate-spin" />
                    <CheckCircle2 v-else class="size-3.5" />
                    Complete sprint
                </Button>
            </div>

            <!-- Progress against the calendar -->
            <div class="px-5 pb-4">
                <div class="mb-1.5 flex items-baseline justify-between text-[11px]">
                    <span class="text-muted-foreground">
                        <span class="text-foreground font-medium tabular-nums">{{ activeSprint.completed_task_count }}</span>
                        of
                        <span class="text-foreground font-medium tabular-nums">{{ activeSprint.task_count }}</span>
                        done
                    </span>

                    <span
                        v-if="report"
                        class="tabular-nums"
                        :class="report.pace_delta < -10 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
                    >
                        {{ report.completion_percentage }}% done · {{ report.time_elapsed_percentage }}% of time gone
                    </span>
                </div>

                <div class="bg-muted relative h-2 overflow-hidden rounded-full">
                    <div
                        class="h-full rounded-full transition-all"
                        :class="report && report.pace_delta < -10 ? 'bg-amber-500' : 'bg-emerald-500'"
                        :style="{ width: `${activeSprint.completion_percentage}%` }"
                    />

                    <!-- Where the calendar says we should be -->
                    <div
                        class="bg-foreground/50 absolute top-0 h-full w-px"
                        :style="{ left: `${activeSprint.time_elapsed_percentage}%` }"
                        aria-hidden="true"
                    />
                </div>
            </div>

            <!-- Burndown -->
            <div v-if="report && report.burndown.length > 1" class="border-t px-5 py-4">
                <p class="text-muted-foreground mb-2 text-[11px] font-medium tracking-wide uppercase">Burndown</p>

                <SprintBurndown :points="report.burndown" :total-days="report.total_days" />
            </div>

            <!-- What to do about it -->
            <div v-if="report && report.recommendations.length > 0" class="bg-muted/20 flex flex-col gap-1.5 border-t px-5 py-3.5">
                <p v-for="note in report.recommendations" :key="note" class="text-muted-foreground flex items-start gap-2 text-xs leading-relaxed">
                    <TriangleAlert
                        class="mt-0.5 size-3.5 shrink-0"
                        :class="report.health === 'on_track' ? 'text-muted-foreground' : 'text-amber-500'"
                    />
                    {{ note }}
                </p>
            </div>

            <!-- Numbers worth knowing -->
            <dl v-if="report" class="grid grid-cols-2 gap-px border-t sm:grid-cols-4">
                <div class="bg-card px-5 py-3">
                    <dt class="text-muted-foreground text-[10px] tracking-wide uppercase">Open</dt>
                    <dd class="text-sm font-semibold tabular-nums">{{ report.open_tasks }}</dd>
                </div>
                <div class="bg-card px-5 py-3">
                    <dt class="text-muted-foreground text-[10px] tracking-wide uppercase">Overdue</dt>
                    <dd class="text-sm font-semibold tabular-nums" :class="report.overdue_tasks > 0 && 'text-red-600 dark:text-red-400'">
                        {{ report.overdue_tasks }}
                    </dd>
                </div>
                <div class="bg-card px-5 py-3">
                    <dt class="text-muted-foreground text-[10px] tracking-wide uppercase">Cycle time</dt>
                    <dd class="text-sm font-semibold tabular-nums">
                        {{ report.average_cycle_time_days === null ? '—' : `${report.average_cycle_time_days}d` }}
                    </dd>
                </div>
                <div class="bg-card px-5 py-3">
                    <dt class="text-muted-foreground text-[10px] tracking-wide uppercase">Velocity</dt>
                    <dd class="text-sm font-semibold tabular-nums">
                        {{ report.velocity_average === null ? '—' : report.velocity_average }}
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Everything else -->
        <div class="bg-card rounded-xl border p-5 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <CalendarRange class="text-muted-foreground size-4" />
                    <h3 class="text-[15px] font-semibold tracking-tight">{{ activeSprint ? 'Other sprints' : 'Sprints' }}</h3>
                </div>

                <Button v-if="canManage && !isFormOpen" size="sm" variant="outline" class="gap-1.5" @click="startCreate">
                    <Plus class="size-3.5" />
                    New sprint
                </Button>
            </div>

            <form v-if="isFormOpen" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
                <AppFormInput id="sprint-name" v-model="form.name" label="Name" :error="form.errors.name" required />

                <AppFormInput id="sprint-goal" v-model="form.goal" label="Goal (optional)" :error="form.errors.goal" />

                <AppFormInput id="sprint-starts-on" v-model="form.starts_on" type="date" label="Starts on" :error="form.errors.starts_on" required />

                <AppFormInput id="sprint-ends-on" v-model="form.ends_on" type="date" label="Ends on" :error="form.errors.ends_on" required />

                <div class="flex items-center gap-2 sm:col-span-2">
                    <Button type="submit" size="sm" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="mr-2 size-3.5 animate-spin" />
                        {{ editingId === null ? 'Create sprint' : 'Save changes' }}
                    </Button>

                    <Button type="button" size="sm" variant="ghost" :disabled="form.processing" @click="cancel">Cancel</Button>
                </div>

                <p v-if="editingId === null" class="text-muted-foreground text-[11px] sm:col-span-2">
                    New sprints are created as planned. Start one when the team is ready to commit to it.
                </p>
            </form>

            <ul v-if="otherSprints.length > 0" class="mt-4 flex flex-col gap-2">
                <li
                    v-for="sprint in otherSprints"
                    :key="sprint.id"
                    class="hover:border-foreground/15 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 transition-colors"
                >
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium">{{ sprint.name }}</span>

                            <span
                                class="rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                                :class="sprintStatusStyles[sprint.status]"
                            >
                                {{ sprint.status_label }}
                            </span>
                        </div>

                        <p class="text-muted-foreground mt-0.5 text-[11px]">
                            {{ formatSprintRange(sprint) }} ·
                            <template v-if="sprint.status === 'completed'">
                                {{ sprint.completed_task_count }} of {{ sprint.committed_task_count ?? sprint.task_count }} done<template
                                    v-if="sprint.carried_over_task_count"
                                >
                                    · {{ sprint.carried_over_task_count }} carried over</template
                                >
                            </template>
                            <template v-else> {{ sprint.task_count }} {{ sprint.task_count === 1 ? 'task' : 'tasks' }} </template>
                        </p>

                        <p v-if="sprint.goal" class="text-muted-foreground mt-1 text-xs">{{ sprint.goal }}</p>
                    </div>

                    <div v-if="canManage && sprint.status !== 'completed'" class="flex shrink-0 items-center gap-1.5">
                        <Button
                            v-if="sprint.status === 'planned'"
                            size="sm"
                            variant="outline"
                            class="h-7 gap-1.5 text-xs"
                            :disabled="runningId === sprint.id || activeSprint !== null"
                            :title="activeSprint ? 'Complete the running sprint first' : undefined"
                            @click="start(sprint)"
                        >
                            <Loader2 v-if="runningId === sprint.id" class="size-3.5 animate-spin" />
                            <Play v-else class="size-3.5" />
                            Start
                        </Button>

                        <Button size="sm" variant="ghost" class="h-7 text-xs" @click="startEdit(sprint)">Edit</Button>

                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground hover:text-destructive h-7 w-7 p-0"
                            :disabled="deletingId === sprint.id"
                            @click="destroy(sprint)"
                        >
                            <Loader2 v-if="deletingId === sprint.id" class="size-3.5 animate-spin" />
                            <Trash2 v-else class="size-3.5" />
                        </Button>
                    </div>
                </li>
            </ul>

            <p v-else-if="!isFormOpen && !activeSprint" class="text-muted-foreground mt-4 text-xs">
                No sprints yet. Plan one to commit to a slice of work and track how it lands.
            </p>
        </div>

        <!-- Completing a sprint is a decision about the unfinished work -->
        <AppModal
            :open="completingSprint !== null"
            title="Complete sprint"
            :description="completingSprint ? `Close ${completingSprint.name} and freeze its result.` : undefined"
            size="sm"
            @update:open="(value: boolean) => !value && (completingSprint = null)"
        >
            <div v-if="completingSprint" class="space-y-4 pt-1">
                <p class="text-muted-foreground text-xs leading-relaxed">
                    <span class="text-foreground font-medium">{{ completingSprint.completed_task_count }}</span> finished,
                    <span class="text-foreground font-medium">{{ openTaskCount }}</span> still open. Completed sprints cannot be reopened.
                </p>

                <div v-if="openTaskCount > 0" class="space-y-2">
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[.06em] uppercase">Unfinished work</p>

                    <button
                        type="button"
                        class="w-full rounded-lg border p-3 text-left transition-colors"
                        :class="carryOver === 'backlog' ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                        @click="carryOver = 'backlog'"
                    >
                        <p class="text-sm font-medium">Back to the backlog</p>
                        <p class="text-muted-foreground mt-0.5 text-xs">Tasks keep their board position but leave the sprint.</p>
                    </button>

                    <button
                        type="button"
                        class="w-full rounded-lg border p-3 text-left transition-colors"
                        :class="carryOver === 'next_sprint' ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                        :disabled="!hasNextPlannedSprint"
                        @click="carryOver = 'next_sprint'"
                    >
                        <p class="text-sm font-medium">Carry into the next planned sprint</p>
                        <p class="text-muted-foreground mt-0.5 text-xs">
                            {{ hasNextPlannedSprint ? `Moves them into ${plannedSprints[0].name}.` : 'No planned sprint to carry into yet.' }}
                        </p>
                    </button>
                </div>
            </div>

            <template #footer>
                <Button type="button" variant="outline" :disabled="runningId !== null" @click="completingSprint = null">Cancel</Button>
                <Button type="button" :disabled="runningId !== null" @click="confirmComplete">
                    <Loader2 v-if="runningId !== null" class="mr-2 size-4 animate-spin" />
                    Complete sprint
                </Button>
            </template>
        </AppModal>
    </div>
</template>
