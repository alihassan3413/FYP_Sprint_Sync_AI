<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import { PROJECT_ROLES, type ProjectMember, type ProjectRoleValue } from '@/lib/projects';

interface PickableMember {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    open: boolean;
    projectId: number;
    workspaceMembers: PickableMember[];
    projectMembers: ProjectMember[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    added: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const availableMembers = computed(() => {
    const assignedIds = new Set(props.projectMembers.map((m) => m.id));
    return props.workspaceMembers.filter((m) => !assignedIds.has(m.id));
});

const form = useForm<{
    user_id: number | null;
    role: ProjectRoleValue;
}>({
    user_id: null,
    role: 'member',
});

function submit() {
    if (!form.user_id) return;

    form.post(workspaceRoute('workspace.projects.members.store', { project: props.projectId }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('added');
            reset();
            emit('update:open', false);
        },
    });
}

function reset() {
    form.reset();
    form.clearErrors();
}

function handleClose(value: boolean) {
    if (form.processing) return;

    if (!value) {
        reset();
    }

    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        title="Add project member"
        description="Give a workspace member access to this project."
        size="md"
        @update:open="handleClose"
    >
        <form id="add-project-member-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <div class="grid gap-1.5">
                <Label class="text-sm font-medium">Workspace member</Label>
                <WorkspaceMemberPicker v-model="form.user_id" :members="availableMembers" />
                <InputError :message="form.errors.user_id" />
            </div>

            <div class="grid gap-2">
                <Label class="text-sm font-medium">Project role</Label>
                <button
                    v-for="option in PROJECT_ROLES"
                    :key="option.value"
                    type="button"
                    class="w-full rounded-lg border p-3 text-left transition-colors"
                    :class="form.role === option.value ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                    @click="form.role = option.value"
                >
                    <p class="text-sm font-medium">{{ option.label }}</p>
                    <p class="text-muted-foreground mt-0.5 text-xs leading-relaxed">{{ option.description }}</p>
                </button>
                <InputError :message="form.errors.role" />
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="submit" form="add-project-member-form" :disabled="form.processing || !form.user_id">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Adding…' : 'Add member' }}
            </Button>
        </template>
    </AppModal>
</template>
