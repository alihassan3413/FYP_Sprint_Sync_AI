<script setup lang="ts">
import { Plus, Video } from 'lucide-vue-next';

import { isPastMeeting, type Meeting } from '@/lib/meetings';

const props = defineProps<{
    meetings: Meeting[];
    canManage: boolean;
}>();

const emit = defineEmits<{
    (e: 'create'): void;
    (e: 'open', meeting: Meeting): void;
    (e: 'edit', meeting: Meeting): void;
    (e: 'delete', meeting: Meeting): void;
}>();

const upcoming = computed(() => props.meetings.filter((meeting) => !isPastMeeting(meeting)));
const past = computed(() => props.meetings.filter((meeting) => isPastMeeting(meeting)));
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <p class="text-muted-foreground text-xs">{{ meetings.length }} {{ meetings.length === 1 ? 'meeting' : 'meetings' }}</p>

            <Button v-if="canManage" size="sm" class="gap-1.5" @click="emit('create')">
                <Plus class="size-3.5" />
                New meeting
            </Button>
        </div>

        <AppEmptyState
            v-if="meetings.length === 0"
            title="No meetings scheduled"
            description="Schedule a meeting to get everyone aligned on this project."
        >
            <template #icon>
                <Video class="size-5" />
            </template>
            <template v-if="canManage" #actions>
                <Button size="sm" class="gap-1.5" @click="emit('create')">
                    <Plus class="size-3.5" />
                    New meeting
                </Button>
            </template>
        </AppEmptyState>

        <template v-else>
            <div class="space-y-3">
                <h3 class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Upcoming</h3>

                <div v-if="upcoming.length > 0" class="space-y-2.5">
                    <MeetingCard
                        v-for="meeting in upcoming"
                        :key="meeting.id"
                        :meeting="meeting"
                        :can-manage="canManage"
                        @open="(m) => emit('open', m)"
                        @edit="(m) => emit('edit', m)"
                        @delete="(m) => emit('delete', m)"
                    />
                </div>
                <p v-else class="text-muted-foreground text-xs">No upcoming meetings.</p>
            </div>

            <div v-if="past.length > 0" class="space-y-3">
                <h3 class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Past</h3>

                <div class="space-y-2.5">
                    <MeetingCard
                        v-for="meeting in past"
                        :key="meeting.id"
                        :meeting="meeting"
                        :can-manage="canManage"
                        @open="(m) => emit('open', m)"
                        @edit="(m) => emit('edit', m)"
                        @delete="(m) => emit('delete', m)"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
