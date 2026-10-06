<script setup lang="ts">
import { Copy, Send, UserCog } from 'lucide-vue-next';

import type { Member } from '@/lib/members';

/**
 * Everything about one team member's SprintSync access, behind a single
 * "Manage access" button. Destructive actions sit apart at the bottom.
 * Changing the access role and removing access reuse the existing dialogs,
 * which the page opens when this modal asks for them.
 */
const props = defineProps<{
    open: boolean;
    access: Member;
    personPublicId: string;
    login: { name: string; email: string } | null;
    canManageMembers: boolean;
    canInviteMembers: boolean;
    canManagePeople: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'change-role': [];
    remove: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const notifications = useNotificationStore();
const { copy, isSupported: canCopyToClipboard } = useClipboard();

const busy = ref(false);

const isPending = computed(() => props.access.status === 'pending');
const canChangeAccess = computed(() => props.canManageMembers && props.access.role !== 'owner' && !props.access.is_self);

function run(method: 'post' | 'delete', url: string, closeAfter: boolean) {
    if (busy.value) return;

    busy.value = true;
    router.visit(url, {
        method,
        preserveScroll: true,
        onSuccess: () => closeAfter && emit('update:open', false),
        onFinish: () => (busy.value = false),
    });
}

const resend = () => run('post', workspaceRoute('workspace.invitations.resend', { invitation: props.access.invitation_id }), false);
const cancelInvitation = () => run('delete', workspaceRoute('workspace.invitations.destroy', { invitation: props.access.invitation_id }), true);
const disconnect = () => run('delete', workspaceRoute('workspace.people.user.destroy', { person: props.personPublicId }), true);

async function copyLink() {
    if (!props.access.invite_url) return;

    if (!canCopyToClipboard.value) {
        notifications.error('Your browser blocked clipboard access.');

        return;
    }

    await copy(props.access.invite_url);
    notifications.success('Invite link copied.');
}

function handOff(event: 'change-role' | 'remove') {
    emit('update:open', false);
    if (event === 'change-role') emit('change-role');
    else emit('remove');
}
</script>

<template>
    <AppModal :open="open" title="SprintSync access" size="sm" @update:open="(value) => !busy && emit('update:open', value)">
        <div class="flex flex-col gap-5 pt-1" data-testid="manage-access">
            <!-- Invitation pending -->
            <template v-if="isPending">
                <div>
                    <p class="font-medium text-amber-700 dark:text-amber-400">Invitation pending</p>
                    <p class="text-muted-foreground text-sm">{{ access.email }}</p>
                </div>
                <div v-if="canInviteMembers" class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" class="gap-1.5" :disabled="busy" @click="resend">
                        <Send class="size-3.5" />
                        Resend invite
                    </Button>
                    <Button v-if="access.invite_url" variant="outline" size="sm" class="gap-1.5" @click="copyLink">
                        <Copy class="size-3.5" />
                        Copy invite link
                    </Button>
                </div>
                <div v-if="canInviteMembers" class="border-t pt-4">
                    <button type="button" class="text-destructive text-sm font-medium hover:underline" :disabled="busy" @click="cancelInvitation">
                        Cancel invitation
                    </button>
                    <p class="text-muted-foreground mt-0.5 text-xs">They stay on your team without SprintSync access.</p>
                </div>
            </template>

            <!-- Has access -->
            <template v-else>
                <div class="flex flex-col gap-1.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <AppRoleBadge :role="access.role ?? 'member'" />
                        <span v-if="access.workspace_role_name" class="text-muted-foreground text-sm">{{ access.workspace_role_name }}</span>
                    </div>
                    <p v-if="login" class="text-muted-foreground text-sm">Signs in as {{ login.email }}</p>
                </div>
                <div v-if="canChangeAccess">
                    <Button variant="outline" size="sm" class="gap-1.5" @click="handOff('change-role')">
                        <UserCog class="size-3.5" />
                        Change access role
                    </Button>
                </div>
                <div v-if="canChangeAccess || canManagePeople" class="flex flex-col gap-3 border-t pt-4">
                    <div v-if="canChangeAccess">
                        <button type="button" class="text-destructive text-sm font-medium hover:underline" @click="handOff('remove')">
                            Remove SprintSync access
                        </button>
                        <p class="text-muted-foreground mt-0.5 text-xs">They stay on your team; only their login is removed.</p>
                    </div>
                    <div v-if="canManagePeople && login">
                        <button type="button" class="text-sm font-medium hover:underline" :disabled="busy" @click="disconnect">
                            Disconnect this login
                        </button>
                        <p class="text-muted-foreground mt-0.5 text-xs">Only if this is the wrong login. Their access is not changed.</p>
                    </div>
                </div>
            </template>
        </div>
    </AppModal>
</template>
