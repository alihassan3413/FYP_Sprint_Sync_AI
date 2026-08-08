<script setup lang="ts">
interface Props {
    open: boolean;
    title: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    closeOnOverlayClick?: boolean;
}

withDefaults(defineProps<Props>(), {
    size: 'md',
    closeOnOverlayClick: true,
});

defineEmits<{
    'update:open': [value: boolean];
}>();

const sizeClasses = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-2xl',
    '2xl': 'sm:max-w-4xl',
};
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent
            :class="['flex max-h-[90vh] w-full flex-col', sizeClasses[size]]"
            @pointer-down-outside="(e) => !closeOnOverlayClick && e.preventDefault()"
        >
            <DialogHeader v-if="title || description || $slots.header" class="shrink-0">
                <slot name="header">
                    <DialogTitle>
                        {{ title }}
                    </DialogTitle>

                    <DialogDescription v-if="description">
                        {{ description }}
                    </DialogDescription>
                </slot>
            </DialogHeader>

            <div class="min-h-0 flex-1 overflow-y-auto pb-6">
                <slot />
            </div>

            <DialogFooter v-if="$slots.footer" class="shrink-0">
                <slot name="footer" />
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
