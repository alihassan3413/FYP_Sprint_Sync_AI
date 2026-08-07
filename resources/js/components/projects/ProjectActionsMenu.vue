<script setup lang="ts">
import { Eye, Pencil, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

import type { Project } from '@/lib/projects';

const props = defineProps<{
    project: Project;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'view', project: Project): void;
    (e: 'edit', project: Project): void;
    (e: 'delete', project: Project): void;
}>();

const items = computed<DropdownEntry[]>(() => {
    const p = props.project;

    const view: DropdownEntry = { label: 'View', icon: Eye, onSelect: () => emit('view', p) };

    if (!props.canManage) {
        return [view];
    }

    return [
        view,
        { label: 'Edit', icon: Pencil, onSelect: () => emit('edit', p) },
        null,
        { label: 'Delete', icon: Trash2, destructive: true, onSelect: () => emit('delete', p) },
    ];
});
</script>

<template>
    <AppDropDown :items="items" :heading="`Manage ${project.name}`" align="end" trigger-label="Open project actions" />
</template>
