<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import { ref } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
}

defineProps<Props>();

const isOpen = ref(
    typeof window === 'undefined'
        ? true
        : localStorage.getItem('sidebar') !== 'false'
);

const handleSidebarChange = (open: boolean) => {
    isOpen.value = open;
    localStorage.setItem('sidebar', String(open));
};
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen" :open="isOpen" @update:open="handleSidebarChange">
        <slot />
    </SidebarProvider>
</template>
