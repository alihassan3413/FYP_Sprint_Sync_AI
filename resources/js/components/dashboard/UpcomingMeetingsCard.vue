<script setup lang="ts">
import { CalendarClock, Video } from 'lucide-vue-next';

import { formatDuration, formatMeetingDate, formatMeetingTime } from '@/lib/meetings';

export interface DashboardMeeting {
    id: number;
    title: string;
    project_id: number;
    project_name: string;
    scheduled_at: string;
    duration_minutes: number;
    join_url: string | null;
    is_past: boolean;
    url: string;
}

const timeZone = useUserTimezone();

const props = defineProps<{
    upcoming: DashboardMeeting[];
    past: DashboardMeeting[];
}>();

const hasAny = computed(() => props.upcoming.length > 0 || props.past.length > 0);
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm">
        <div class="mb-4 flex items-center gap-2">
            <CalendarClock class="text-muted-foreground size-4" />
            <h3 class="text-[15px] font-semibold tracking-tight">Meetings</h3>
        </div>

        <div v-if="hasAny" class="flex flex-col gap-5">
            <div v-if="upcoming.length > 0">
                <p class="text-muted-foreground mb-2.5 text-[11px] font-medium tracking-[0.06em] uppercase">Upcoming</p>

                <ul class="divide-border/60 divide-y">
                    <li v-for="meeting in upcoming" :key="meeting.id" class="flex items-start gap-3 py-2.5 first:pt-0">
                        <div class="min-w-0 flex-1">
                            <Link :href="meeting.url" class="text-foreground truncate text-sm font-medium hover:underline">
                                {{ meeting.title }}
                            </Link>
                            <p class="text-muted-foreground mt-0.5 truncate text-xs">
                                {{ meeting.project_name }} · {{ formatMeetingDate(meeting.scheduled_at, timeZone) }} at
                                {{ formatMeetingTime(meeting.scheduled_at, timeZone) }} · {{ formatDuration(meeting.duration_minutes) }}
                            </p>
                        </div>

                        <Button v-if="meeting.join_url" as-child size="sm" variant="outline" class="h-7 shrink-0 gap-1.5 text-xs">
                            <a :href="meeting.join_url" target="_blank" rel="noopener noreferrer">
                                <Video class="size-3.5" />
                                Join
                            </a>
                        </Button>
                    </li>
                </ul>
            </div>

            <div v-if="past.length > 0">
                <p class="text-muted-foreground mb-2.5 text-[11px] font-medium tracking-[0.06em] uppercase">Recently held</p>

                <ul class="divide-border/60 divide-y">
                    <li v-for="meeting in past" :key="meeting.id" class="py-2.5 first:pt-0">
                        <Link :href="meeting.url" class="text-foreground truncate text-sm hover:underline">
                            {{ meeting.title }}
                        </Link>
                        <p class="text-muted-foreground mt-0.5 truncate text-xs">
                            {{ meeting.project_name }} · {{ formatMeetingDate(meeting.scheduled_at, timeZone) }}
                        </p>
                    </li>
                </ul>
            </div>
        </div>

        <p v-else class="text-muted-foreground py-6 text-center text-xs">No meetings scheduled yet. Open a project to schedule one.</p>
    </div>
</template>
