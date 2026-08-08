<script setup lang="ts">
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface ChannelPreference {
    channel: string;
    label: string;
    enabled: boolean;
}

interface TypePreference {
    type: string;
    label: string;
    group: string;
    channels: ChannelPreference[];
}

interface PreferenceGroup {
    group: string;
    items: TypePreference[];
}

const props = defineProps<{
    groups: PreferenceGroup[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notification preferences',
        href: '/settings/notifications',
    },
];

const form = useForm({
    preferences: props.groups.flatMap((group) =>
        group.items.flatMap((item) =>
            item.channels.map((channel) => ({
                type: item.type,
                channel: channel.channel,
                enabled: channel.enabled,
            })),
        ),
    ),
});

function findEntry(type: string, channel: string) {
    return form.preferences.find((preference) => preference.type === type && preference.channel === channel)!;
}

function submit() {
    form.put(route('notification-preferences.update'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notification preferences" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Notification preferences"
                    description="Choose which activity you want to be notified about, and how."
                />

                <form @submit.prevent="submit" class="space-y-8">
                    <div v-for="group in groups" :key="group.group" class="space-y-4">
                        <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ group.group }}</h3>

                        <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 dark:divide-neutral-800 dark:border-neutral-800">
                            <div v-for="item in group.items" :key="item.type" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <span class="text-sm text-neutral-800 dark:text-neutral-200">{{ item.label }}</span>

                                <div class="flex items-center gap-5">
                                    <div v-for="channel in item.channels" :key="channel.channel" class="flex items-center gap-2">
                                        <Checkbox
                                            :id="`${item.type}-${channel.channel}`"
                                            v-model:checked="findEntry(item.type, channel.channel).enabled"
                                        />
                                        <Label :for="`${item.type}-${channel.channel}`" class="cursor-pointer text-xs font-normal text-neutral-600 dark:text-neutral-400">
                                            {{ channel.label }}
                                        </Label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="Object.keys(form.errors).length > 0" class="text-sm text-red-600">
                        Something went wrong saving your preferences. Please try again.
                    </p>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">Save</Button>

                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-sm text-neutral-600">Saved.</p>
                        </TransitionRoot>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
