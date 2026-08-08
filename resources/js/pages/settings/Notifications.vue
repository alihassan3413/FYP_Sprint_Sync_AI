<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, CalendarClock, ListChecks } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

import SavedIndicator from '@/components/settings/SavedIndicator.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import AppSwitch from '@/components/ui/AppSwitch.vue';
import { Button } from '@/components/ui/button';
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

const groupIcons: Record<string, Component> = {
    Meetings: CalendarClock,
    Tasks: ListChecks,
};

const groupDescriptions: Record<string, string> = {
    Meetings: 'Invitations, reschedules and cancellations for meetings you are part of.',
    Tasks: 'Activity on the work assigned to you or that you follow.',
};

const itemDescriptions: Record<string, string> = {
    meeting_scheduled: 'A new meeting is booked with you on the invite list.',
    meeting_updated: 'The time, agenda or attendees of a meeting change.',
    meeting_cancelled: 'A meeting you were invited to is called off.',
    task_assigned: 'A task is handed over to you.',
    task_moved: 'One of your tasks moves to another column.',
    task_comment: 'Someone comments on a task you are involved in.',
};

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

const entries = computed(() => {
    const map: Record<string, { type: string; channel: string; enabled: boolean }> = {};

    form.preferences.forEach((preference) => {
        map[`${preference.type}:${preference.channel}`] = preference;
    });

    return map;
});

function columnsFor(group: PreferenceGroup) {
    const columns: ChannelPreference[] = [];

    group.items.forEach((item) => {
        item.channels.forEach((channel) => {
            if (!columns.some((column) => column.channel === channel.channel)) {
                columns.push(channel);
            }
        });
    });

    return columns;
}

function groupEntries(group: PreferenceGroup) {
    return group.items.flatMap((item) => item.channels.map((channel) => entries.value[`${item.type}:${channel.channel}`]).filter(Boolean));
}

function allEnabled(group: PreferenceGroup) {
    return groupEntries(group).every((entry) => entry.enabled);
}

function toggleGroup(group: PreferenceGroup) {
    const next = !allEnabled(group);

    groupEntries(group).forEach((entry) => {
        entry.enabled = next;
    });
}

const hasErrors = computed(() => Object.keys(form.errors).length > 0);

function submit() {
    form.put(route('notification-preferences.update'), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Notification preferences" />

        <SettingsLayout>
            <SettingsSection
                v-for="group in groups"
                :key="group.group"
                :icon="groupIcons[group.group] ?? Bell"
                :title="group.group"
                :description="groupDescriptions[group.group]"
            >
                <template #aside>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-foreground text-[13px]"
                        @click="toggleGroup(group)"
                    >
                        {{ allEnabled(group) ? 'Turn all off' : 'Turn all on' }}
                    </Button>
                </template>

                <div class="divide-border/60 divide-y">
                    <div class="hidden items-center gap-4 pb-2.5 sm:flex">
                        <div class="flex-1" />
                        <div
                            v-for="column in columnsFor(group)"
                            :key="column.channel"
                            class="text-muted-foreground w-16 text-center text-[11px] font-medium tracking-[0.06em] uppercase"
                        >
                            {{ column.label }}
                        </div>
                    </div>

                    <div v-for="item in group.items" :key="item.type" class="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-center sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-foreground text-[13.5px] font-medium">{{ item.label }}</p>
                            <p v-if="itemDescriptions[item.type]" class="text-muted-foreground mt-0.5 text-[12.5px]">
                                {{ itemDescriptions[item.type] }}
                            </p>
                        </div>

                        <div class="flex items-center gap-6 sm:gap-0">
                            <div
                                v-for="column in columnsFor(group)"
                                :key="column.channel"
                                class="flex flex-col items-center gap-1.5 sm:w-16 sm:gap-0"
                            >
                                <span class="text-muted-foreground text-[11px] sm:hidden">{{ column.label }}</span>

                                <AppSwitch
                                    v-if="entries[item.type + ':' + column.channel]"
                                    v-model="entries[item.type + ':' + column.channel].enabled"
                                    :label="`${item.label} — ${column.label}`"
                                />
                                <span v-else class="text-muted-foreground/40 text-sm" aria-hidden="true">—</span>
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsSection>

            <div
                class="border-border/70 bg-card/85 sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 shadow-[0_8px_24px_-12px_rgba(16,24,40,0.25)] backdrop-blur"
            >
                <p v-if="hasErrors" class="text-destructive text-[13px] font-medium">We couldn't save your preferences. Please try again.</p>
                <SavedIndicator v-else-if="form.recentlySuccessful" show label="Preferences saved" />
                <p v-else class="text-muted-foreground text-[13px]">
                    {{ form.isDirty ? 'You have unsaved changes.' : 'Everything is up to date.' }}
                </p>

                <div class="flex items-center gap-2">
                    <Button v-if="form.isDirty" type="button" variant="ghost" size="sm" :disabled="form.processing" @click="form.reset()">
                        Discard
                    </Button>

                    <Button type="button" size="sm" :disabled="form.processing || !form.isDirty" @click="submit">
                        {{ form.processing ? 'Saving…' : 'Save preferences' }}
                    </Button>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
