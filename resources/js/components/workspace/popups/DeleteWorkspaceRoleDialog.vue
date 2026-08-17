<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

interface RoleSummary {
    id: number;
    name: string;
    member_count?: number | null;
}

const props = defineProps<{
    open: boolean;
    role: RoleSummary | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const processing = ref(false);
const error = ref<string | null>(null);

const memberCount = computed(() => props.role?.member_count ?? 0);

watch(
    () => props.open,
    (open) => {
        if (open) {
            error.value = null;
        }
    },
);

function submit() {
    if (props.role === null || processing.value) return;

    processing.value = true;
    error.value = null;

    router.delete(workspaceRoute('workspace.roles.destroy', { role: props.role.id }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: () => {
            error.value = 'Unable to delete this role.';
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
    <AppModal :open="open" title="Delete role" size="sm" @update:open="handleClose">
        <template #header>
            <DialogTitle class="text-destructive">Delete role</DialogTitle>
            <DialogDescription>
                This permanently deletes <span class="text-foreground font-medium">{{ role?.name }}</span
                >. It cannot be undone.
            </DialogDescription>
        </template>

        <div class="space-y-2 pt-1">
            <p v-if="memberCount > 0" class="text-muted-foreground text-sm">
                <span class="text-foreground font-medium tabular-nums">{{ memberCount }}</span>
                {{ memberCount === 1 ? 'member currently has' : 'members currently have' }} this role. They keep their workspace access and their
                system role — only this label is removed.
            </p>
            <p v-else class="text-muted-foreground text-sm">No members currently have this role.</p>

            <p v-if="error" class="text-destructive text-xs">{{ error }}</p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)">Cancel</Button>
            <Button type="button" variant="destructive" :disabled="processing || role === null" @click="submit">
                <Loader2 v-if="processing" class="mr-2 size-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete role' }}
            </Button>
        </template>
    </AppModal>
</template>
