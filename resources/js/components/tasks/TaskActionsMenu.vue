<script setup lang="ts">
import { Pencil, Trash2 } from 'lucide-vue-next';

import type { Task } from '@/lib/tasks';

const props = defineProps<{
    task: Task;
}>();

const emit = defineEmits<{
    (e: 'edit', task: Task): void;
    (e: 'delete', task: Task): void;
}>();

const items = computed<DropdownEntry[]>(() => [
    { label: 'Edit', icon: Pencil, onSelect: () => emit('edit', props.task) },
    { label: 'Delete', icon: Trash2, destructive: true, onSelect: () => emit('delete', props.task) },
]);
</script>

<template>
    <AppDropDown :items="items" :heading="`Manage “${task.title}”`" align="end" width="w-44" trigger-label="Open task actions" />
</template>
