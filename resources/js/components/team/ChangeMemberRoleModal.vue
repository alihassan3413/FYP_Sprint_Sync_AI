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

const roleOptions = [
    { value: 'admin', label: 'Admin', description: 'Can manage members, roles, and workspace settings.' },
    { value: 'member', label: 'Member', description: 'Standard access to projects and tasks.' },
] as const;

const selectedRole = ref<'admin' | 'member'>('member');
const processing = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.member,
    (member) => {
        error.value = null;
        if (member?.role === 'admin' || member?.role === 'member') {
            selectedRole.value = member.role;
        }
    },
    { immediate: true },
);

function submit() {
    if (!props.member || processing.value) return;

    processing.value = true;
    error.value = null;

    router.patch(
        workspaceRoute('workspace.members.update', props.member.id),
        { role: selectedRole.value },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (errors) => {
                error.value = errors.role ?? "Unable to update this member's role.";
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function handleClose(value: boolean) {
    if (processing.value) return;
    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        title="Change role"
        :description="member ? `Update ${member.name}'s role in this workspace.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <div class="space-y-2 pt-1">
            <button
                v-for="option in roleOptions"
                :key="option.value"
                type="button"
                class="w-full rounded-lg border p-3 text-left transition-colors"
                :class="selectedRole === option.value ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                @click="selectedRole = option.value"
            >
                <p class="text-sm font-medium">{{ option.label }}</p>
                <p class="text-muted-foreground mt-0.5 text-xs leading-relaxed">{{ option.description }}</p>
            </button>

            <p v-if="error" class="text-destructive text-xs">{{ error }}</p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="button" :disabled="processing" @click="submit">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Saving…' : 'Save changes' }}
            </Button>
        </template>
    </AppModal>
</template>
