<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Monitor, Moon, Palette, Sun } from 'lucide-vue-next';

import SettingsSection from '@/components/settings/SettingsSection.vue';
import { useAppearance } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', label: 'Light', hint: 'Crisp and bright', icon: Sun },
    { value: 'dark', label: 'Dark', hint: 'Easy after hours', icon: Moon },
    { value: 'system', label: 'System', hint: 'Follows your device', icon: Monitor },
] as const;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Appearance settings" />

        <SettingsLayout>
            <SettingsSection :icon="Palette" title="Theme" description="Choose how the workspace looks. Saved to this browser.">
                <div role="radiogroup" class="grid gap-4 sm:grid-cols-3">
                    <label
                        v-for="option in options"
                        :key="option.value"
                        :class="[
                            'group relative cursor-pointer rounded-xl border p-2.5 transition-all',
                            appearance === option.value
                                ? 'border-foreground/80 ring-foreground/80 shadow-[0_1px_2px_rgba(16,24,40,0.06)] ring-1'
                                : 'border-border hover:border-foreground/25 hover:bg-muted/30',
                        ]"
                    >
                        <input
                            type="radio"
                            name="appearance"
                            class="sr-only"
                            :value="option.value"
                            :checked="appearance === option.value"
                            @change="updateAppearance(option.value)"
                        />

                        <div class="border-border/70 overflow-hidden rounded-lg border">
                            <div class="flex h-[74px]">
                                <div
                                    v-if="option.value !== 'dark'"
                                    :class="['flex bg-white', option.value === 'system' ? 'w-1/2 shrink-0' : 'w-full']"
                                >
                                    <div class="flex w-[30%] shrink-0 flex-col gap-1 bg-zinc-900 p-1.5">
                                        <span class="h-1 w-full rounded-full bg-lime-400/90" />
                                        <span class="h-1 w-3/4 rounded-full bg-zinc-700" />
                                        <span class="h-1 w-4/5 rounded-full bg-zinc-700" />
                                    </div>
                                    <div class="flex flex-1 flex-col gap-1.5 p-2">
                                        <span class="h-1.5 w-1/2 rounded-full bg-zinc-300" />
                                        <span class="h-1 w-full rounded-full bg-zinc-200" />
                                        <span class="h-8 w-full rounded-md border border-zinc-200 bg-zinc-50" />
                                    </div>
                                </div>

                                <div
                                    v-if="option.value !== 'light'"
                                    :class="['flex bg-[hsl(224_36%_8%)]', option.value === 'system' ? 'w-1/2 shrink-0' : 'w-full']"
                                >
                                    <div class="flex w-[30%] shrink-0 flex-col gap-1 bg-[hsl(224_30%_11%)] p-1.5">
                                        <span class="h-1 w-full rounded-full bg-sky-400/90" />
                                        <span class="h-1 w-3/4 rounded-full bg-slate-700" />
                                        <span class="h-1 w-4/5 rounded-full bg-slate-700" />
                                    </div>
                                    <div class="flex flex-1 flex-col gap-1.5 p-2">
                                        <span class="h-1.5 w-1/2 rounded-full bg-slate-600" />
                                        <span class="h-1 w-full rounded-full bg-slate-700" />
                                        <span class="h-8 w-full rounded-md border border-slate-700/70 bg-[hsl(224_32%_12%)]" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 px-1 pt-2.5 pb-1">
                            <component
                                :is="option.icon"
                                :class="['size-4 shrink-0', appearance === option.value ? 'text-foreground' : 'text-muted-foreground']"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="text-foreground text-[13px] font-medium">{{ option.label }}</p>
                                <p class="text-muted-foreground text-[11.5px]">{{ option.hint }}</p>
                            </div>

                            <span
                                v-if="appearance === option.value"
                                class="bg-foreground text-background flex size-4 shrink-0 items-center justify-center rounded-full"
                            >
                                <Check class="size-2.5" />
                            </span>
                        </div>
                    </label>
                </div>
            </SettingsSection>
        </SettingsLayout>
    </AppLayout>
</template>
