<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { Meeting } from '@/lib/meetings';

const props = defineProps<{
    open: boolean;
    meeting: Meeting | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    deleted: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

function confirm() {
    if (!props.meeting || processing.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.projects.meetings.destroy', { project: props.meeting.project_id, meeting: props.meeting.id }), {
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
        title="Delete meeting"
        :description="meeting ? `“${meeting.title}” will be permanently deleted. This cannot be undone.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="processing" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete meeting' }}
            </Button>
        </template>
    </AppModal>
</template>
