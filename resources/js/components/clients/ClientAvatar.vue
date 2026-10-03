<script setup lang="ts">
import { clientInitials } from '@/lib/clients';

const props = withDefaults(
    defineProps<{
        name: string;
        logoUrl: string | null;
        size?: 'md' | 'lg';
    }>(),
    { size: 'md' },
);

/* A missing or unreadable image falls back to the initials tile instead of a broken icon. */
const failed = ref(false);

watch(
    () => props.logoUrl,
    () => (failed.value = false),
);

const sizeClasses = computed(() => (props.size === 'lg' ? 'size-14 rounded-xl text-lg' : 'size-10 rounded-lg text-sm'));
</script>

<template>
    <div
        v-if="logoUrl && !failed"
        :class="['flex shrink-0 items-center justify-center overflow-hidden border bg-white p-1', sizeClasses]"
        data-testid="client-logo"
    >
        <img :src="logoUrl" :alt="`${name} logo`" class="size-full object-contain" loading="lazy" @error="failed = true" />
    </div>
    <div v-else :class="['bg-foreground text-primary flex shrink-0 items-center justify-center font-bold', sizeClasses]" aria-hidden="true">
        {{ clientInitials(name) }}
    </div>
</template>
