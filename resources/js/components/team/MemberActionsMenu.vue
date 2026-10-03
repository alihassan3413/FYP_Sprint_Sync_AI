<script setup lang="ts">
/**
 * MemberActionsMenu — every action for one Team row, so the row itself stays
 * calm. Which items appear depends on the row's SprintSync access state and
 * on what the viewer may do; destructive items always sit last, after a
 * separator.
 *
 * Emits semantic events. The page performs the mutations.
 */

import { Copy, Link2, Send, Trash2, UserCog, UserPen, UserPlus } from 'lucide-vue-next';
import { computed } from 'vue';

import type { Member } from '@/lib/members';

interface Props {
    member: Member;
    /** Change access role / remove access */
    canManageMembers: boolean;
    /** Resend / copy / cancel invitations, give access */
    canInviteMembers: boolean;
    /** Owner: team profiles (job details, connecting logins) */
    canManagePeople: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'resend-invite', member: Member): void;
    (e: 'copy-invite-link', member: Member): void;
    (e: 'revoke-invite', member: Member): void;
    (e: 'change-role', member: Member): void;
    (e: 'remove', member: Member): void;
    (e: 'give-access', member: Member): void;
    (e: 'connect', member: Member): void;
    (e: 'add-details', member: Member): void;
}>();

const items = computed<DropdownEntry[]>(() => {
    const m = props.member;
    const main: DropdownEntry[] = [];
    const destructive: DropdownEntry[] = [];

    if (m.status === 'pending' && props.canInviteMembers) {
        main.push({ label: 'Resend invite', icon: Send, onSelect: () => emit('resend-invite', m) });
        if (m.invite_url) main.push({ label: 'Copy invite link', icon: Copy, onSelect: () => emit('copy-invite-link', m) });
        destructive.push({ label: 'Cancel invitation', icon: Trash2, destructive: true, onSelect: () => emit('revoke-invite', m) });
    }

    if (m.status === 'none' && props.canManagePeople && props.canInviteMembers) {
        main.push({ label: 'Give SprintSync access', icon: UserPlus, onSelect: () => emit('give-access', m) });
    }

    if (m.status === 'active') {
        if (m.link_suggested && props.canManagePeople) {
            main.push({ label: 'Connect profile', icon: Link2, onSelect: () => emit('connect', m) });
        }

        if (!m.person && props.canManagePeople && m.role !== 'client') {
            main.push({ label: 'Add job details', icon: UserPen, onSelect: () => emit('add-details', m) });
        }

        if (props.canManageMembers && m.role !== 'owner' && !m.is_self) {
            main.push({ label: 'Change access role', icon: UserCog, onSelect: () => emit('change-role', m) });
            destructive.push({ label: 'Remove SprintSync access', icon: Trash2, destructive: true, onSelect: () => emit('remove', m) });
        }
    }

    return destructive.length && main.length ? [...main, null, ...destructive] : [...main, ...destructive];
});

const heading = computed(() => `${props.member.name?.split(' ')[0] || 'Team member'}`);
</script>

<template>
    <AppDropDown v-if="items.length" :items="items" :heading="heading" align="end" width="w-56" trigger-label="Open team member actions" />
</template>
