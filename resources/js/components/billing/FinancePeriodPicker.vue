<script setup lang="ts">
/**
 * This month / last month / custom dates, in the workspace's calendar. Changing
 * it reloads the page with ?period=… so the view can be bookmarked.
 */
const props = defineProps<{
    period: { key: string; from: string; to: string };
    /** Extra query to keep, e.g. the display currency. */
    query?: Record<string, string>;
}>();

const key = ref(props.period.key);
const from = ref(props.period.from);
const to = ref(props.period.to);

const options = [
    { value: 'this_month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'custom', label: 'Custom' },
];

function load() {
    const params: Record<string, string> = { ...props.query, period: key.value };
    if (key.value === 'custom') Object.assign(params, { from: from.value, to: to.value });

    router.get(window.location.pathname, params, { preserveScroll: true, preserveState: true });
}

watch(key, (value) => value !== 'custom' && load());
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" data-testid="period-picker">
        <AppSegmentedControl v-model="key" :options="options" />
        <form v-if="key === 'custom'" class="flex flex-wrap items-center gap-2 text-sm" @submit.prevent="load">
            <input v-model="from" type="date" class="bg-background h-8 rounded-md border px-2" aria-label="From" required />
            <span class="text-muted-foreground">to</span>
            <input v-model="to" type="date" :min="from" class="bg-background h-8 rounded-md border px-2" aria-label="To" required />
            <Button type="submit" size="sm" variant="outline">Show</Button>
        </form>
        <span v-else class="text-muted-foreground text-xs tabular-nums">{{ period.from }} – {{ period.to }}</span>
    </div>
</template>
