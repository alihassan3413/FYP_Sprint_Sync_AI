<script setup lang="ts">
import { CalendarRange, Loader2, Plus, Trash2 } from 'lucide-vue-next';

import { formatSprintRange, sprintStatusLabel, type Sprint } from '@/lib/sprints';

const props = defineProps<{
    projectId: number;
    sprints: Sprint[];
    canManage: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const isCreating = ref(false);
const editingId = ref<number | null>(null);
const deletingId = ref<number | null>(null);

const form = useForm({
    name: '',
    goal: '',
    starts_on: '',
    ends_on: '',
});

function startCreate() {
    form.clearErrors();
    form.reset();
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

function destroy(sprint: Sprint) {
    deletingId.value = sprint.id;

    router.delete(workspaceRoute('workspace.projects.sprints.destroy', { project: props.projectId, sprint: sprint.id }), {
        preserveScroll: true,
        onFinish: () => (deletingId.value = null),
    });
}

const isFormOpen = computed(() => isCreating.value || editingId.value !== null);
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <CalendarRange class="text-muted-foreground size-4" />
                <h3 class="text-[15px] font-semibold tracking-tight">Sprints</h3>
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
        </form>

        <ul v-if="sprints.length > 0" class="mt-4 flex flex-col gap-2">
            <li
                v-for="sprint in sprints"
                :key="sprint.id"
                class="hover:border-foreground/15 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 transition-colors"
                :class="sprint.is_current && 'border-emerald-500/40 bg-emerald-500/5'"
            >
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="truncate text-sm font-medium">{{ sprint.name }}</span>
                        <span
                            class="rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                            :class="
                                sprint.is_current
                                    ? 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                                    : 'border-border text-muted-foreground'
                            "
                        >
                            {{ sprintStatusLabel(sprint) }}
                        </span>
                    </div>

                    <p class="text-muted-foreground mt-0.5 text-[11px]">
                        {{ formatSprintRange(sprint) }} · {{ sprint.task_count }} {{ sprint.task_count === 1 ? 'task' : 'tasks' }}
                    </p>

                    <p v-if="sprint.goal" class="text-muted-foreground mt-1 text-xs">{{ sprint.goal }}</p>
                </div>

                <div v-if="canManage" class="flex shrink-0 items-center gap-1.5">
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

        <p v-else-if="!isFormOpen" class="text-muted-foreground mt-4 text-xs">No sprints yet. Create one to track sprint progress in Analytics.</p>
    </div>
</template>
