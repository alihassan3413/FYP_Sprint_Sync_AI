<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { ProjectMember } from '@/lib/projects';

const props = defineProps<{
    open: boolean;
    projectId: number;
    member: ProjectMember | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

function confirm() {
    if (!props.member || processing.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.projects.members.destroy', { project: props.projectId, member: props.member.id }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
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
        title="Remove project member"
        :description="member ? `${member.name} will lose access to this project.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="processing" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Removing…' : 'Remove member' }}
            </Button>
        </template>
    </AppModal>
</template>
