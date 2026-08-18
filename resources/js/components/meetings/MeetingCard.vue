<script setup lang="ts">
import { CalendarClock, Clock, Video } from 'lucide-vue-next';

import { formatDuration, formatMeetingDate, formatMeetingTime, isPastMeeting, isValidMeetingLink, type Meeting } from '@/lib/meetings';

const props = defineProps<{
    meeting: Meeting;
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'open', meeting: Meeting): void;
    (e: 'edit', meeting: Meeting): void;
    (e: 'delete', meeting: Meeting): void;
}>();

const timeZone = useUserTimezone();
const past = computed(() => isPastMeeting(props.meeting));
const hasJoinLink = computed(() => isValidMeetingLink(props.meeting.meeting_link));
</script>

<template>
    <div
        class="bg-card hover:border-foreground/15 flex cursor-pointer flex-col gap-3 rounded-lg border p-4 shadow-sm transition-colors sm:flex-row sm:items-center sm:justify-between"
        :class="past && 'opacity-70'"
        @click="emit('open', meeting)"
    >
        <div class="min-w-0 space-y-1.5">
            <div class="flex items-center gap-2">
                <p class="text-sm font-medium">{{ meeting.title }}</p>
                <AppBadge :variant="past ? 'neutral' : 'success'" size="sm">{{ past ? 'Past' : 'Upcoming' }}</AppBadge>
            </div>

            <p v-if="meeting.description" class="text-muted-foreground line-clamp-2 text-xs leading-relaxed">
                {{ meeting.description }}
            </p>

            <div class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span class="inline-flex items-center gap-1">
                    <CalendarClock class="size-3" />
                    {{ formatMeetingDate(meeting.scheduled_at, timeZone) }} · {{ formatMeetingTime(meeting.scheduled_at, timeZone) }}
                </span>
                <span class="inline-flex items-center gap-1">
                    <Clock class="size-3" />
                    {{ formatDuration(meeting.duration_minutes) }}
                </span>
                <span v-if="!hasJoinLink" class="text-muted-foreground/70">No link added</span>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2 self-end sm:self-auto">
            <Button
                v-if="hasJoinLink"
                as="a"
                :href="meeting.meeting_link!"
                target="_blank"
                rel="noopener noreferrer"
                size="sm"
                class="gap-1.5"
                @click.stop
            >
                <Video class="size-3.5" />
                Join Meeting
            </Button>

            <div v-if="canManage" @click.stop>
                <MeetingActionsMenu :meeting="meeting" @edit="(m) => emit('edit', m)" @delete="(m) => emit('delete', m)" />
            </div>
        </div>
    </div>
</template>
