<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { BoardColumn } from '@/lib/tasks';

const props = defineProps<{
    open: boolean;
    column: BoardColumn | null;
    projectId: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    deleted: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

function confirm() {
    if (!props.column || processing.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.projects.board-columns.destroy', { project: props.projectId, boardColumn: props.column.id }), {
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
        title="Delete column"
        :description="column ? `“${column.name}” will be permanently deleted. This only works if the column is empty.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="processing" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete column' }}
            </Button>
        </template>
    </AppModal>
</template>
