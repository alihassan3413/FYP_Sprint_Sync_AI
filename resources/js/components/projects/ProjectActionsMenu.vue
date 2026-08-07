<script setup lang="ts">
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

import type { Project } from '@/lib/projects';

const props = defineProps<{
    project: Project;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit', project: Project): void;
    (e: 'delete', project: Project): void;
}>();

const items = computed<DropdownEntry[]>(() => {
    if (!props.canManage) {
        return [];
    }

    const p = props.project;

    return [
        { label: 'Edit', icon: Pencil, onSelect: () => emit('edit', p) },
        { label: 'Delete', icon: Trash2, destructive: true, onSelect: () => emit('delete', p) },
    ];
});
</script>

<template>
    <AppDropDown :items="items" :heading="`Manage ${project.name}`" align="end" trigger-label="Open project actions" />
</template>
