<script setup lang="ts">
import { Trash2, UserCog } from 'lucide-vue-next';
import { computed } from 'vue';

import type { ProjectMember } from '@/lib/projects';

const props = defineProps<{
    member: ProjectMember;
}>();

const emit = defineEmits<{
    (e: 'change-role', member: ProjectMember): void;
    (e: 'remove', member: ProjectMember): void;
}>();

const items = computed<DropdownEntry[]>(() => [
    { label: 'Change project role', icon: UserCog, onSelect: () => emit('change-role', props.member) },
    null,
    { label: 'Remove from project', icon: Trash2, destructive: true, onSelect: () => emit('remove', props.member) },
]);

const heading = computed(() => `Manage ${props.member.name?.split(' ')[0] || 'member'}`);
</script>

<template>
    <AppDropDown :items="items" :heading="heading" align="end" trigger-label="Open project member actions" />
</template>
