<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

interface WorkspaceProfile {
    id: number;
    name: string;
    slug: string;
}

const props = defineProps<{
    open: boolean;
    workspace: WorkspaceProfile;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const confirmation = ref('');
const processing = ref(false);
const error = ref<string | null>(null);

const canDelete = computed(() => confirmation.value.trim() === props.workspace.name && !processing.value);

watch(
    () => props.open,
    (open) => {
        if (open) {
            confirmation.value = '';
            error.value = null;
        }
    },
);

function submit() {
    if (!canDelete.value) return;

    processing.value = true;
    error.value = null;

    router.delete(workspaceRoute('workspace.destroy'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: () => {
            error.value = 'Unable to delete this workspace.';
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
    <AppModal :open="open" title="Delete workspace" size="sm" @update:open="handleClose">
        <template #header>
            <DialogTitle class="text-destructive">Delete workspace</DialogTitle>
            <DialogDescription>
                This will permanently delete <span class="text-foreground font-medium">{{ workspace.name }}</span> and all of its projects, tasks,
                members, and data. This action cannot be undone.
            </DialogDescription>
        </template>

        <div class="space-y-2 pt-1">
            <label for="delete-workspace-confirm" class="text-sm font-medium">
                Type <span class="font-semibold">{{ workspace.name }}</span> to confirm
            </label>
            <Input id="delete-workspace-confirm" v-model="confirmation" autocomplete="off" @keyup.enter="submit" />
            <p v-if="error" class="text-destructive text-xs">{{ error }}</p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" variant="destructive" :disabled="!canDelete" @click="submit">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Deleting…' : 'Delete workspace' }}
            </Button>
        </template>
    </AppModal>
</template>
