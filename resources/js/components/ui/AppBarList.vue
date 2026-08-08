<script setup lang="ts">
/**
 * AppBarList — a compact horizontal bar chart for label/count breakdowns.
 *
 * Usage:
 *   <AppBarList :items="[{ label: 'To Do', count: 4 }, { label: 'Done', count: 9, tone: 'success' }]" />
 */

import { computed } from 'vue';

interface BarItem {
    label: string;
    count: number;
    tone?: 'default' | 'success';
}

interface Props {
    items: BarItem[];
    emptyLabel?: string;
}

const props = withDefaults(defineProps<Props>(), {
    emptyLabel: 'No data yet.',
});

const max = computed(() => Math.max(1, ...props.items.map((item) => item.count)));
</script>

<template>
    <div v-if="items.length > 0" class="space-y-3">
        <div v-for="item in items" :key="item.label" class="flex items-center gap-3">
            <span class="text-foreground w-28 shrink-0 truncate text-sm" :title="item.label">{{ item.label }}</span>
            <div class="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                <div
                    class="h-full rounded-full transition-all"
                    :class="item.tone === 'success' ? 'bg-emerald-500' : 'bg-foreground/70'"
                    :style="{ width: `${(item.count / max) * 100}%` }"
                />
            </div>
            <span class="text-muted-foreground w-8 shrink-0 text-right text-xs tabular-nums">{{ item.count }}</span>
        </div>
    </div>

    <p v-else class="text-muted-foreground py-6 text-center text-xs">{{ emptyLabel }}</p>
</template>
