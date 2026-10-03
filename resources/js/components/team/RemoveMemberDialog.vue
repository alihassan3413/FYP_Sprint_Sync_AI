<script setup lang="ts">
import type { Member } from '@/lib/members';
import { Loader2 } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    member: Member | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

function confirm() {
    if (!props.member || processing.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.members.destroy', { user: props.member.id }), {
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
        title="Remove SprintSync access"
        :description="
            member
                ? `${member.name} will no longer be able to sign in to this workspace. They stay on your team; only their login is removed.`
                : undefined
        "
        size="sm"
        @update:open="handleClose"
    >
        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="processing" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Removing…' : 'Remove access' }}
            </Button>
        </template>
    </AppModal>
</template>
