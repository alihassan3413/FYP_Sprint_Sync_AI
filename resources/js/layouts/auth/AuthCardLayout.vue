<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-muted/40 p-6 md:p-10">
        <div class="flex w-full max-w-md flex-col gap-6">
            <Link :href="route('home')" class="flex items-center justify-center gap-2.5 self-center">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-foreground text-background">
                    <AppLogoIcon class="size-5 fill-current" />
                </div>
                <span class="text-sm font-semibold tracking-tight">Sprint Sync</span>
            </Link>

            <Card class="rounded-xl shadow-sm">
                <CardHeader class="px-8 pt-8 pb-0 text-center">
                    <CardTitle class="text-xl font-semibold">{{ title }}</CardTitle>
                    <CardDescription class="mt-1 text-sm">{{ description }}</CardDescription>
                </CardHeader>
                <CardContent class="px-8 pb-8 pt-6">
                    <slot />
                </CardContent>
            </Card>
        </div>
    </div>
</template>
