<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import AppToaster from '@/components/ui/AppToaster.vue';
import { useFlashToasts } from '@/composables/useFlashToasts'
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

useFlashToasts()
</script>

<template>
        <AppLayout :breadcrumbs="breadcrumbs">
            <slot />
        </AppLayout>

        <AppToaster />
</template>
