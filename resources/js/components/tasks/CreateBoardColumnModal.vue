<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    projectId: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm<{ name: string }>({ name: '' });

function submit() {
    form.post(workspaceRoute('workspace.projects.board-columns.store', { project: props.projectId }), {
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
    <AppModal :open="open" title="Add column" description="Add a new stage to this project's board." size="sm" @update:open="handleClose">
        <form id="create-board-column-form" class="pt-2" @submit.prevent="submit">
            <AppFormInput
                id="board-column-name"
                v-model="form.name"
                label="Column name"
                placeholder="e.g. QA"
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

            <Button type="submit" form="create-board-column-form" :disabled="form.processing || form.name.trim().length < 1">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Adding…' : 'Add column' }}
            </Button>
        </template>
    </AppModal>
</template>
