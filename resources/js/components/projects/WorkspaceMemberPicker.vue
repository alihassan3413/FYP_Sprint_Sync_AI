<script setup lang="ts">
import { ChevronDown, User as UserIcon } from 'lucide-vue-next';

interface PickableMember {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    members: PickableMember[];
    modelValue: number | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

const selected = computed(() => props.members.find((m) => m.id === props.modelValue) ?? null);

const items = computed<DropdownEntry[]>(() =>
    props.members.map((member) => ({
        label: member.name,
        onSelect: () => emit('update:modelValue', member.id),
    })),
);
</script>

<template>
    <AppDropDown :items="items" align="start" width="w-64" trigger-label="Choose a workspace member">
        <template #trigger>
            <button
                type="button"
                class="border-input bg-background hover:bg-muted/40 flex h-10 w-full items-center gap-2 rounded-md border px-3 text-sm transition-colors"
            >
                <AppAvatar v-if="selected" :name="selected.name" size="xs" />
                <UserIcon v-else class="text-muted-foreground size-4" />
                <span class="flex-1 text-left" :class="!selected && 'text-muted-foreground'">{{ selected?.name ?? 'Select a member' }}</span>
                <ChevronDown class="text-muted-foreground size-3.5" />
            </button>
        </template>
    </AppDropDown>
</template>
