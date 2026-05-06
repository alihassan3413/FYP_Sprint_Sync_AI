<script setup lang="ts">
/**
 * MemberActionsMenu — domain wrapper around AppDropdownMenu.
 *
 * Owns the policy for what actions appear for a given member based on:
 *   - Status (pending → resend/copy/revoke; otherwise → change role/transfer/remove)
 *   - is_self (you can't remove yourself)
 *
 * Emits semantic events. Page handles the actual mutations (router.delete,
 * router.post, etc.) — this component just describes the menu.
 */

import { computed } from 'vue';
import { Copy, Mail, Send, Shuffle, Trash2, UserCog } from 'lucide-vue-next';

import type { Member } from '@/lib/members';

interface Props {
  member: Member;
}

const props = defineProps<Props>();

const emit = defineEmits<{
  (e: 'resend-invite', member: Member): void;
  (e: 'copy-invite-link', member: Member): void;
  (e: 'change-role', member: Member): void;
  (e: 'transfer-tasks', member: Member): void;
  (e: 'remove', member: Member): void;
  (e: 'revoke-invite', member: Member): void;
}>();

const items = computed<DropdownEntry[]>(() => {
  const m = props.member;
  const isPending = m.status === 'pending';

  if (isPending) {
    return [
      { label: 'Resend invitation', icon: Send,
        onSelect: () => emit('resend-invite', m) },
      { label: 'Copy invite link', icon: Copy,
        onSelect: () => emit('copy-invite-link', m) },
      null,
      { label: 'Revoke invite', icon: Trash2, destructive: true,
        onSelect: () => emit('revoke-invite', m) },
    ];
  }

  return [
    { label: 'Change role', icon: UserCog,
      onSelect: () => emit('change-role', m) },
    { label: 'Transfer tasks', icon: Shuffle,
      onSelect: () => emit('transfer-tasks', m) },
    ...(m.is_self ? [] : [
      null,
      { label: 'Remove', icon: Trash2, destructive: true,
        onSelect: () => emit('remove', m) },
    ] as DropdownEntry[]),
  ];
});

const heading = computed(
  () => `Manage ${props.member.name?.split(' ')[0] || 'member'}`,
);
</script>

<template>
  <AppDropDown
    :items="items"
    :heading="heading"
    align="end"
    trigger-label="Open member actions"
  />
</template>
