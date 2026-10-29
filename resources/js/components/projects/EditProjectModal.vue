<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { Project } from '@/lib/projects';
import { timezoneOptions, zoneLabel } from '@/lib/timezones';

const props = defineProps<{
    open: boolean;
    project: Project | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    updated: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm({
    name: '',
    description: '',
    timezone: '',
});

const timezoneChoices = timezoneOptions();

watch(
    () => props.project,
    (project) => {
        form.clearErrors();
        form.name = project?.name ?? '';
        form.description = project?.description ?? '';
        form.timezone = project?.timezone ?? '';
    },
    { immediate: true },
);

function submit() {
    if (!props.project) return;

    form.put(workspaceRoute('workspace.projects.update', { project: props.project.id }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('updated');
            emit('update:open', false);
        },
    });
}

function handleClose(value: boolean) {
    if (form.processing) return;
    emit('update:open', value);
}
</script>

<template>
    <AppModal :open="open" title="Edit project" size="md" @update:open="handleClose">
        <form v-if="project" id="edit-project-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="edit-project-name"
                v-model="form.name"
                label="Project name"
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="edit-project-description" class="text-sm font-medium"
                    >Description <span class="text-muted-foreground font-normal">(optional)</span></Label
                >
                <Textarea
                    id="edit-project-description"
                    v-model="form.description"
                    placeholder="What is this project for?"
                    rows="3"
                    :aria-invalid="!!form.errors.description"
                />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid gap-1.5">
                <Label for="edit-project-timezone" class="text-sm font-medium">Working timezone</Label>
                <select
                    id="edit-project-timezone"
                    v-model="form.timezone"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                    :aria-invalid="!!form.errors.timezone"
                    :class="form.errors.timezone && 'border-destructive focus-visible:ring-destructive'"
                    data-testid="project-timezone"
                >
                    <option value="">Workspace default ({{ zoneLabel(project.workspace_timezone ?? 'UTC') }})</option>
                    <option v-for="option in timezoneChoices" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <p class="text-muted-foreground text-xs">New timers and manual time on this project follow this timezone. Past time keeps its own.</p>
                <InputError :message="form.errors.timezone" />
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

            <Button type="submit" form="edit-project-form" :disabled="form.processing || form.name.trim().length < 2">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Saving…' : 'Save changes' }}
            </Button>
        </template>
    </AppModal>
</template>
