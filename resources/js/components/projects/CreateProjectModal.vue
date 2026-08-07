<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

defineProps<{ open: boolean }>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm({
    name: '',
    description: '',
});

function submit() {
    form.post(workspaceRoute('workspace.projects.store'), {
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
    <AppModal :open="open" title="New project" description="Give your project a name teammates will recognize." size="md" @update:open="handleClose">
        <form id="create-project-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="project-name"
                v-model="form.name"
                label="Project name"
                placeholder="e.g. Mobile Redesign"
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="project-description" class="text-sm font-medium">Description <span class="text-muted-foreground font-normal">(optional)</span></Label>
                <Textarea
                    id="project-description"
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

            <Button type="submit" form="create-project-form" :disabled="form.processing || form.name.trim().length < 2">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Creating…' : 'Create project' }}
            </Button>
        </template>
    </AppModal>
</template>
