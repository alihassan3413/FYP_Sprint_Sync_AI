<script setup lang="ts">
import { Check } from 'lucide-vue-next';

import type { AppSelectOption } from '@/components/ui/AppSelect.vue';

const props = defineProps<{
    option: AppSelectOption;
    selected: boolean;
    query?: string;
}>();

const fold = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

const match = computed(() => {
    const needle = fold(props.query?.trim() ?? '');

    if (!needle) {
        return null;
    }

    const characters = [...props.option.label];
    const positions: number[] = [];
    let folded = '';

    characters.forEach((character, position) => {
        for (const part of fold(character)) {
            folded += part;
            positions.push(position);
        }
    });

    const index = folded.indexOf(needle);

    if (index < 0) {
        return null;
    }

    const start = positions[index];
    const end = positions[index + needle.length - 1] + 1;

    return {
        before: characters.slice(0, start).join(''),
        hit: characters.slice(start, end).join(''),
        after: characters.slice(end).join(''),
    };
});
</script>

<template>
    <AppAvatar v-if="option.avatar" :name="option.avatar.name" :src="option.avatar.src" size="xs" class="shrink-0" />
    <component :is="option.icon" v-else-if="option.icon" class="text-muted-foreground size-4 shrink-0" />
    <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate" :class="selected && 'text-foreground font-medium'">
            <slot>
                <template v-if="match"
                    >{{ match.before }}<mark class="text-foreground bg-transparent font-semibold">{{ match.hit }}</mark>{{ match.after }}</template
                >
                <template v-else>{{ option.label }}</template>
            </slot>
        </span>
        <span v-if="option.description" class="text-muted-foreground truncate text-xs leading-4">{{ option.description }}</span>
    </span>
    <span v-if="option.meta" class="text-muted-foreground shrink-0 text-xs tabular-nums">{{ option.meta }}</span>
    <Check class="text-primary-text size-4 shrink-0 stroke-[2.5]" :class="selected ? 'opacity-100' : 'opacity-0'" aria-hidden="true" />
</template>
