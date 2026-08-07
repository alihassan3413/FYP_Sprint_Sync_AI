<script setup lang="ts">
import { ChevronDown, User as UserIcon } from 'lucide-vue-next';

import type { TaskMember } from '@/lib/tasks';

const props = defineProps<{
    members: TaskMember[];
    modelValue: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const selected = computed(() => props.members.find((m) => m.id === props.modelValue) ?? null);

const items = computed<DropdownEntry[]>(() => [
    { label: 'Unassigned', icon: UserIcon, onSelect: () => emit('update:modelValue', null) },
    null,
    ...props.members.map((member) => ({
        label: member.name,
        onSelect: () => emit('update:modelValue', member.id),
    })),
]);
</script>

<template>
    <AppDropDown :items="items" align="start" width="w-56" trigger-label="Choose assignee">
        <template #trigger>
            <button
                type="button"
                class="border-input bg-background hover:bg-muted/40 flex h-10 w-full items-center gap-2 rounded-md border px-3 text-sm transition-colors"
            >
                <AppAvatar v-if="selected" :name="selected.name" size="xs" />
                <UserIcon v-else class="text-muted-foreground size-4" />
                <span class="flex-1 text-left">{{ selected?.name ?? 'Unassigned' }}</span>
                <ChevronDown class="text-muted-foreground size-3.5" />
            </button>
        </template>
    </AppDropDown>
</template>
