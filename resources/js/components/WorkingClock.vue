<script setup lang="ts">
import { Clock } from 'lucide-vue-next';

import { zoneLabel } from '@/lib/timezones';

const props = defineProps<{
    timezone: string;
    caption?: string;
}>();

const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => (now.value = new Date()), 15_000);
});
onBeforeUnmount(() => clearInterval(timer));

const time = computed(() => now.value.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZone: props.timezone }));
const label = computed(() => zoneLabel(props.timezone, now.value));
</script>

<template>
    <span class="text-muted-foreground inline-flex items-center gap-1.5 text-xs" :title="timezone" data-testid="working-clock">
        <Clock class="size-3.5" />
        <span v-if="caption">{{ caption }}</span>
        <time class="text-foreground font-medium tabular-nums" :datetime="now.toISOString()">{{ time }}</time>
        <span>{{ label }}</span>
    </span>
</template>
