<script setup lang="ts">
interface Props {
    id?: string;
    label?: string;
    disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
    disabled: false,
});

const model = defineModel<boolean>({ default: false });
</script>

<template>
    <button
        :id="id"
        type="button"
        role="switch"
        :aria-checked="model"
        :aria-label="label"
        :disabled="disabled"
        :class="[
            'relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200',
            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
            'disabled:cursor-not-allowed disabled:opacity-40',
            model ? 'bg-foreground' : 'bg-muted-foreground/25 hover:bg-muted-foreground/40',
        ]"
        @click="model = !model"
    >
        <span
            :class="[
                'pointer-events-none inline-block size-4 rounded-full bg-background shadow-sm ring-1 ring-black/5 transition-transform duration-200',
                model ? 'translate-x-[18px]' : 'translate-x-0.5',
            ]"
        />
    </button>
</template>
