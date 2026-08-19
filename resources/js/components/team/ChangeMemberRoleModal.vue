<script setup lang="ts">
import type { Member, WorkspaceRoleOption } from '@/lib/members';
import { Check, Loader2 } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        open: boolean;
        member: Member | null;
        workspaceRoles?: WorkspaceRoleOption[];
    }>(),
    {
        workspaceRoles: () => [],
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const roleOptions = [
    { value: 'admin', label: 'Admin', description: 'Can manage members, roles, and workspace settings.' },
    { value: 'member', label: 'Member', description: 'Standard access to projects and tasks.' },
    {
        value: 'client',
        label: 'Client',
        description: 'External guest. Sees only the projects they are added to, limited by their client role below.',
    },
] as const;

const selectedRole = ref<'admin' | 'member' | 'client'>('member');
const selectedWorkspaceRoleId = ref<number | null>(null);
const processing = ref(false);
const error = ref<string | null>(null);

watch(
    () => props.member,
    (member) => {
        error.value = null;

        if (member?.role === 'admin' || member?.role === 'member' || member?.role === 'client') {
            selectedRole.value = member.role;
        }

        selectedWorkspaceRoleId.value = member?.workspace_role_id ?? null;
    },
    { immediate: true },
);

function submit() {
    if (!props.member || processing.value) return;

    processing.value = true;
    error.value = null;

    router.patch(
        workspaceRoute('workspace.members.update', { user: props.member.id }),
        {
            role: selectedRole.value,
            workspace_role_id: selectedWorkspaceRoleId.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
            onError: (errors) => {
                error.value = errors.role ?? errors.workspace_role_id ?? "Unable to update this member's role.";
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
        <div class="space-y-4 pt-1">
            <div class="space-y-2">
                <p class="text-muted-foreground text-[11px] font-medium tracking-[.06em] uppercase">System role</p>

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
            </div>

            <div v-if="workspaceRoles.length > 0" class="space-y-2">
                <p class="text-muted-foreground text-[11px] font-medium tracking-[.06em] uppercase">Custom role</p>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                        :class="selectedWorkspaceRoleId === null ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                        @click="selectedWorkspaceRoleId = null"
                    >
                        <Check v-if="selectedWorkspaceRoleId === null" class="size-3" />
                        None
                    </button>

                    <button
                        v-for="workspaceRole in workspaceRoles"
                        :key="workspaceRole.id"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                        :class="selectedWorkspaceRoleId === workspaceRole.id ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                        @click="selectedWorkspaceRoleId = workspaceRole.id"
                    >
                        <Check v-if="selectedWorkspaceRoleId === workspaceRole.id" class="size-3" />
                        {{ workspaceRole.name }}
                    </button>
                </div>

                <p class="text-muted-foreground text-[11px] leading-relaxed">
                    <template v-if="selectedRole === 'client'">
                        For clients, the custom role decides what they can do in their projects — comment, request tasks, close tasks.
                    </template>
                    <template v-else> Custom roles describe what someone does on the team, and can grant extra workspace permissions. </template>
                </p>
            </div>

            <p v-if="error" class="text-destructive text-xs">{{ error }}</p>
        </div>

        <template #footer>
            <Button type="button" variant="outline" :disabled="processing" @click="handleClose(false)">Cancel</Button>
            <Button type="button" :disabled="processing" @click="submit">
                <Loader2 v-if="processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ processing ? 'Saving…' : 'Save changes' }}
            </Button>
        </template>
    </AppModal>
</template>
