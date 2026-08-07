<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { Project } from '@/lib/projects';

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
});

watch(
    () => props.project,
    (project) => {
        form.clearErrors();
        form.name = project?.name ?? '';
        form.description = project?.description ?? '';
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
                <Label for="edit-project-description" class="text-sm font-medium">Description <span class="text-muted-foreground font-normal">(optional)</span></Label>
                <Textarea
                    id="edit-project-description"
                    v-model="form.description"
                    placeholder="What is this project for?"
                    rows="3"
                    :aria-invalid="!!form.errors.description"
                />
                <InputError :message="form.errors.description" />
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
