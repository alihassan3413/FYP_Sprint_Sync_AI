<script setup lang="ts">
interface Props {
    open: boolean;
    title: string;
    description?: string;
    size?: 'sm' | 'md' | 'lg' | 'xl';
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
};
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent
            :class="['w-full', sizeClasses[size]]"
            @pointer-down-outside="(e) => !closeOnOverlayClick && e.preventDefault()"
        >
            <DialogHeader v-if="title || description || $slots.header">
                <slot name="header">
                    <DialogTitle>
                        {{ title }}
                    </DialogTitle>

                    <DialogDescription v-if="description">
                        {{ description }}
                    </DialogDescription>
                </slot>
            </DialogHeader>

            <div>
                <slot />
            </div>

            <DialogFooter v-if="$slots.footer">
                <slot name="footer" />
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
