<script setup lang="ts">
import type { Component } from 'vue';

interface Props {
    title: string;
    description?: string;
    icon?: Component;
    tone?: 'default' | 'danger';
}

withDefaults(defineProps<Props>(), {
    tone: 'default',
});
</script>

<template>
    <section
        :class="[
            'bg-card overflow-hidden rounded-xl border shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
            tone === 'danger' ? 'border-destructive/25' : 'border-border/70',
        ]"
    >
        <header class="flex items-start gap-3.5 px-5 pt-5 sm:px-6 sm:pt-6">
            <span
                v-if="icon"
                :class="[
                    'flex size-9 shrink-0 items-center justify-center rounded-lg border',
                    tone === 'danger'
                        ? 'border-destructive/20 bg-destructive/5 text-destructive'
                        : 'border-border/70 bg-muted/50 text-muted-foreground',
                ]"
            >
                <component :is="icon" class="size-[18px]" />
            </span>

            <div class="min-w-0 flex-1">
                <h2 class="text-foreground text-[15px] leading-6 font-semibold tracking-tight">
                    {{ title }}
                </h2>
                <p v-if="description" class="text-muted-foreground mt-0.5 text-[13px] leading-relaxed">
                    {{ description }}
                </p>
            </div>

            <div v-if="$slots.aside" class="shrink-0">
                <slot name="aside" />
            </div>
        </header>

        <div class="px-5 py-5 sm:px-6">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-border/70 bg-muted/30 flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3.5 sm:px-6"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
