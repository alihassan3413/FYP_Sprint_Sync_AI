<script setup lang="ts">
import { Package, Plus, UserRound } from 'lucide-vue-next';

import type { TeamMemberOption } from '@/lib/billing';

/**
 * One field for adding a line: typing shows matching team members, and for
 * fixed invoices also offers to add the text as a plain item. Keyboard: ↑/↓ to
 * move, Enter to add, Escape to close.
 */
const props = defineProps<{
    teamMembers: TeamMemberOption[];
    /** Team members already on the invoice. */
    usedPersonIds: string[];
    allowItems: boolean;
}>();

const emit = defineEmits<{
    add: [line: { person: string | null; description: string; role_label: string | null }];
}>();

const query = ref('');
const open = ref(false);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

interface Option {
    key: string;
    label: string;
    hint: string | null;
    person: TeamMemberOption | null;
}

const options = computed<Option[]>(() => {
    const text = query.value.trim();
    const needle = text.toLowerCase();

    const members = props.teamMembers
        .filter((member) => !props.usedPersonIds.includes(member.public_id))
        .filter((member) => !needle || member.name.toLowerCase().includes(needle) || member.title?.toLowerCase().includes(needle))
        .slice(0, 8)
        .map((member) => ({ key: member.public_id, label: member.name, hint: member.title, person: member }));

    const exact = props.teamMembers.some((member) => member.name.toLowerCase() === needle);
    const item = props.allowItems && text && !exact ? [{ key: '__item', label: `Add "${text}" as an item`, hint: null, person: null }] : [];

    return [...members, ...item];
});

watch(options, () => (active.value = 0));

function choose(option: Option | undefined) {
    if (!option) return;

    emit(
        'add',
        option.person
            ? { person: option.person.public_id, description: option.person.name, role_label: option.person.title }
            : { person: null, description: query.value.trim(), role_label: null },
    );

    query.value = '';
    open.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        active.value = Math.min(active.value + 1, options.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (open.value) choose(options.value[active.value]);
    } else if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        open.value = false;
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative" @focusout="(event) => !(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node) && (open = false)">
        <label
            class="focus-within:border-ring focus-within:bg-background flex items-center gap-2 rounded-lg border border-dashed px-3 transition-colors"
        >
            <Plus class="text-muted-foreground size-4 shrink-0" />
            <input
                ref="input"
                v-model="query"
                class="h-10 w-full bg-transparent text-sm outline-none"
                :placeholder="allowItems ? 'Add a team member or item…' : 'Add a team member…'"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="open && options.length > 0"
                aria-controls="plan-line-options"
                data-testid="line-adder"
                @focus="open = true"
                @input="open = true"
                @keydown="onKeydown"
            />
        </label>

        <ul
            v-if="open && options.length"
            id="plan-line-options"
            role="listbox"
            class="bg-popover absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-lg border p-1 shadow-lg"
        >
            <li
                v-for="(option, index) in options"
                :key="option.key"
                role="option"
                :aria-selected="index === active"
                class="flex cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-sm"
                :class="index === active ? 'bg-accent' : 'hover:bg-muted/60'"
                @mousedown.prevent="choose(option)"
                @mouseenter="active = index"
            >
                <span class="bg-muted flex size-7 shrink-0 items-center justify-center rounded-full">
                    <UserRound v-if="option.person" class="size-3.5" />
                    <Package v-else class="size-3.5" />
                </span>
                <span class="min-w-0 flex-1 truncate">
                    <span class="font-medium">{{ option.label }}</span>
                    <span v-if="option.hint" class="text-muted-foreground"> · {{ option.hint }}</span>
                </span>
            </li>
        </ul>
        <p v-else-if="open && !allowItems && query && !options.length" class="text-muted-foreground mt-1 px-1 text-xs">
            Nobody on your team matches "{{ query }}". Add them in Team first.
        </p>
    </div>
</template>
