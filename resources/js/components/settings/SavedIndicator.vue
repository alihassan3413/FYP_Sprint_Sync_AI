<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Check } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        show: boolean;
        label?: string;
    }>(),
    {
        label: 'Saved',
    },
);
</script>

<template>
    <TransitionRoot
        :show="show"
        enter="transition duration-200 ease-out"
        enter-from="translate-y-1 opacity-0"
        enter-to="translate-y-0 opacity-100"
        leave="transition duration-150 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
    >
        <p class="inline-flex items-center gap-1.5 text-[13px] font-medium text-emerald-600 dark:text-emerald-400">
            <Check class="size-3.5" />
            {{ label }}
        </p>
    </TransitionRoot>
</template>
