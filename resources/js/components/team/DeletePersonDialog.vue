<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import type { Member } from '@/lib/members';

const props = defineProps<{
    open: boolean;
    member: Member | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);

const blocker = computed(() => {
    if (props.member?.status === 'active') {
        return `${props.member.name} still has SprintSync access to this workspace. Remove their access first from the same menu, then delete them from the team.`;
    }

    if (props.member?.status === 'pending') {
        return `${props.member.name} has a pending invitation. Cancel the invitation first, then delete them from the team.`;
    }

    return null;
});

function confirm() {
    if (!props.member?.person || processing.value || blocker.value) return;

    processing.value = true;

    router.delete(workspaceRoute('workspace.people.destroy', { person: props.member.person.public_id }), {
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
    <AppModal :open="open" :title="member ? `Delete ${member.name} from the team?` : 'Delete from team'" size="sm" @update:open="handleClose">
        <div v-if="member" class="space-y-3 text-sm" data-testid="delete-person-dialog">
            <p v-if="blocker" class="rounded-lg border border-amber-500/30 bg-amber-500/5 px-3 py-2.5 text-amber-800 dark:text-amber-300">
                {{ blocker }}
            </p>
            <template v-else>
                <p>They'll disappear from Team, Time and every list where you pick a person. A running timer is stopped first.</p>
                <p class="text-muted-foreground">
                    Their SprintSync account and any other workspaces they belong to aren't affected. Hours they already recorded stay in past totals
                    and invoices, so nothing already paid or billed changes.
                </p>
            </template>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)">{{ blocker ? 'Close' : 'Cancel' }}</Button>
            <Button v-if="!blocker" type="button" variant="destructive" :disabled="processing" data-testid="confirm-delete-person" @click="confirm">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete from team' }}
            </Button>
        </template>
    </AppModal>
</template>
