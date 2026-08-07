<script setup lang="ts">
import { Pencil, Trash2 } from 'lucide-vue-next';

import type { Meeting } from '@/lib/meetings';

const props = defineProps<{
    meeting: Meeting;
}>();

const emit = defineEmits<{
    (e: 'edit', meeting: Meeting): void;
    (e: 'delete', meeting: Meeting): void;
}>();

const items = computed<DropdownEntry[]>(() => [
    { label: 'Edit', icon: Pencil, onSelect: () => emit('edit', props.meeting) },
    { label: 'Delete', icon: Trash2, destructive: true, onSelect: () => emit('delete', props.meeting) },
]);
</script>

<template>
    <AppDropDown :items="items" :heading="`Manage “${meeting.title}”`" align="end" width="w-44" trigger-label="Open meeting actions" />
</template>
