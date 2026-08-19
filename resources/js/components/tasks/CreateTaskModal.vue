<script setup lang="ts">
import type { Sprint } from '@/lib/sprints';
import { Loader2 } from 'lucide-vue-next';

import type { TaskMember } from '@/lib/tasks';

const props = defineProps<{
    sprints?: Sprint[];
    open: boolean;
    projectId: number;
    members: TaskMember[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm<{
    title: string;
    description: string;
    assigned_to: number | null;
    due_date: string;
    sprint_id: string;
}>({
    title: '',
    description: '',
    assigned_to: null,
    due_date: '',
    sprint_id: '',
});

function submit() {
    form.post(workspaceRoute('workspace.projects.tasks.store', { project: props.projectId }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('created');
            reset();
            emit('update:open', false);
        },
    });
}

function reset() {
    form.reset();
    form.clearErrors();
}

function handleClose(value: boolean) {
    if (form.processing) return;

    if (!value) {
        reset();
    }

    emit('update:open', value);
}
</script>

<template>
    <AppModal :open="open" title="New task" description="Add a task to this project's board." size="md" @update:open="handleClose">
        <form id="create-task-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="task-title"
                v-model="form.title"
                label="Title"
                placeholder="e.g. Wireframe the onboarding flow"
                :error="form.errors.title"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="task-description" class="text-sm font-medium">
                    Description <span class="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <Textarea id="task-description" v-model="form.description" placeholder="Any context teammates should know?" rows="3" />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-1.5">
                    <Label class="text-sm font-medium">Assignee</Label>
                    <AssigneePicker v-model="form.assigned_to" :members="members" />
                    <InputError :message="form.errors.assigned_to" />
                </div>

                <AppFormInput id="task-due-date" v-model="form.due_date" type="date" label="Due date" :error="form.errors.due_date" />
                <div class="grid gap-1.5">
                    <label :for="'task-sprint'" class="text-foreground text-sm font-medium">Sprint</label>
                    <select
                        :id="'task-sprint'"
                        v-model="form.sprint_id"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                    >
                        <option value="">No sprint</option>
                        <option v-for="sprint in sprints" :key="sprint.id" :value="String(sprint.id)">
                            {{ sprint.name }}{{ sprint.is_current ? ' (current)' : '' }}
                        </option>
                    </select>
                    <InputError :message="form.errors.sprint_id" />
                </div>
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

            <Button type="submit" form="create-task-form" :disabled="form.processing || form.title.trim().length < 2">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Creating…' : 'Create task' }}
            </Button>
        </template>
    </AppModal>
</template>
