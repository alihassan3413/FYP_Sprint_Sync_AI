<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import { PROJECT_ROLES, type ProjectMember, type ProjectRoleValue } from '@/lib/projects';

const props = defineProps<{
    open: boolean;
    projectId: number;
    member: ProjectMember | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const selectedRole = ref<ProjectRoleValue>('member');
const processing = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.member,
    (member) => {
        error.value = null;
        if (member) {
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
        workspaceRoute('workspace.projects.members.update', { project: props.projectId, member: props.member.id }),
        { role: selectedRole.value },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (errors) => {
                error.value = errors.role ?? "Unable to update this member's project role.";
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
        title="Change project role"
        :description="member ? `Update ${member.name}'s role on this project.` : undefined"
        size="sm"
        @update:open="handleClose"
    >
        <div class="space-y-2 pt-1">
            <button
                v-for="option in PROJECT_ROLES"
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
