<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { Task } from '@/lib/tasks';

const props = defineProps<{
    open: boolean;
    task: Task | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    deleted: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

function confirm() {
    if (!props.task || processing.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.projects.tasks.destroy', { project: props.task.project_id, task: props.task.id }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('deleted');
            emit('update:open', false);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function handleClose(value: boolean) {
    if (processing.value) return;
    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        title="Delete task"
        :description="task ? `“${task.title}” will be permanently deleted. This cannot be undone.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="processing" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete task' }}
            </Button>
        </template>
    </AppModal>
</template>
