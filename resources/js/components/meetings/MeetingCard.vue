<script setup lang="ts">
import { CalendarClock, Clock, ExternalLink } from 'lucide-vue-next';

import { formatDuration, formatMeetingDate, formatMeetingTime, isPastMeeting, type Meeting } from '@/lib/meetings';

const props = defineProps<{
    meeting: Meeting;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit', meeting: Meeting): void;
    (e: 'delete', meeting: Meeting): void;
}>();

const past = computed(() => isPastMeeting(props.meeting));
</script>

<template>
    <div
        class="bg-card flex flex-col gap-3 rounded-lg border p-4 shadow-sm transition-colors sm:flex-row sm:items-center sm:justify-between"
        :class="past && 'opacity-70'"
    >
        <div class="min-w-0 space-y-1.5">
            <div class="flex items-center gap-2">
                <p class="text-sm font-medium">{{ meeting.title }}</p>
                <AppBadge v-if="past" variant="neutral" size="sm">Past</AppBadge>
            </div>

            <p v-if="meeting.description" class="text-muted-foreground line-clamp-2 text-xs leading-relaxed">
                {{ meeting.description }}
            </p>

            <div class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span class="inline-flex items-center gap-1">
                    <CalendarClock class="size-3" />
                    {{ formatMeetingDate(meeting.scheduled_at) }} · {{ formatMeetingTime(meeting.scheduled_at) }}
                </span>
                <span class="inline-flex items-center gap-1">
                    <Clock class="size-3" />
                    {{ formatDuration(meeting.duration_minutes) }}
                </span>
                <a
                    v-if="meeting.meeting_link"
                    :href="meeting.meeting_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-foreground hover:underline inline-flex items-center gap-1"
                    @click.stop
                >
                    <ExternalLink class="size-3" />
                    Join link
                </a>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 self-end sm:self-auto">
            <div v-if="canManage" @click.stop>
                <MeetingActionsMenu :meeting="meeting" @edit="(m) => emit('edit', m)" @delete="(m) => emit('delete', m)" />
            </div>
        </div>
    </div>
</template>
