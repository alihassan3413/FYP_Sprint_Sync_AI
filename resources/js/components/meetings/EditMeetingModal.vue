<script setup lang="ts">
import type { ParticipantOption } from '@/components/meetings/MeetingParticipantPicker.vue';
import { CalendarClock, Clock, Loader2, User as UserIcon, Video } from 'lucide-vue-next';

import {
    formatDuration,
    formatMeetingDate,
    formatMeetingTime,
    isPastMeeting,
    isValidMeetingLink,
    toDateTimeLocalValue,
    type Meeting,
} from '@/lib/meetings';

const props = defineProps<{
    open: boolean;
    meeting: Meeting | null;
    canManage: boolean;
    participantOptions?: ParticipantOption[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    updated: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const timeZone = useUserTimezone();
const timezoneHint = computed(() => `Times are shown in ${timeZone.value} (${timezoneOffsetLabel(timeZone.value)}).`);

const form = useForm<{
    title: string;
    description: string;
    scheduled_at: string;
    duration_minutes: string;
    meeting_link: string;
    participant_user_ids: number[];
    participant_emails: string[];
}>({
    title: '',
    description: '',
    scheduled_at: '',
    duration_minutes: '30',
    meeting_link: '',
    participant_user_ids: [],
    participant_emails: [],
});

watch(
    () => props.meeting,
    (meeting) => {
        form.clearErrors();
        form.title = meeting?.title ?? '';
        form.description = meeting?.description ?? '';
        form.scheduled_at = meeting ? toDateTimeLocalValue(meeting.scheduled_at, timeZone.value) : '';
        form.duration_minutes = meeting ? String(meeting.duration_minutes) : '30';
        form.meeting_link = meeting?.meeting_link ?? '';
        form.participant_user_ids = (meeting?.participants ?? []).filter((p) => p.user_id !== null).map((p) => p.user_id as number);
        form.participant_emails = (meeting?.participants ?? []).filter((p) => p.is_external).map((p) => p.email);
    },
    { immediate: true },
);

const past = computed(() => (props.meeting ? isPastMeeting(props.meeting) : false));
const hasJoinLink = computed(() => isValidMeetingLink(props.meeting?.meeting_link));

function submit() {
    if (!props.meeting || !props.canManage) return;

    form.put(workspaceRoute('workspace.projects.meetings.update', { project: props.meeting.project_id, meeting: props.meeting.id }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('updated');
            emit('update:open', false);
        },
    });
}

function handleClose(value: boolean) {
    if (form.processing) return;
    emit('update:open', value);
}
</script>

<template>
    <AppModal :open="open" :title="canManage ? 'Edit meeting' : 'Meeting details'" size="md" @update:open="handleClose">
        <form v-if="meeting && canManage" id="edit-meeting-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="edit-meeting-title"
                v-model="form.title"
                label="Title"
                :error="form.errors.title"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="edit-meeting-description" class="text-sm font-medium">
                    Agenda <span class="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <Textarea id="edit-meeting-description" v-model="form.description" placeholder="What's this meeting about?" rows="3" />
                <InputError :message="form.errors.description" />
            </div>

            <AppFormInput
                id="edit-meeting-scheduled-at"
                v-model="form.scheduled_at"
                type="datetime-local"
                label="Date & time"
                :hint="timezoneHint"
                :error="form.errors.scheduled_at"
                required
            />

            <div class="grid grid-cols-2 gap-4">
                <AppFormInput
                    id="edit-meeting-duration"
                    v-model="form.duration_minutes"
                    type="number"
                    label="Duration (min)"
                    :error="form.errors.duration_minutes"
                    required
                />

                <AppFormInput
                    id="edit-meeting-link"
                    v-model="form.meeting_link"
                    type="url"
                    label="Meeting link"
                    placeholder="https://…"
                    :error="form.errors.meeting_link"
                />
            </div>

            <p class="text-muted-foreground text-xs">Meeting link is optional — paste a Zoom, Meet, or Teams URL.</p>

            <MeetingParticipantPicker
                v-model:user-ids="form.participant_user_ids"
                v-model:emails="form.participant_emails"
                :options="participantOptions ?? []"
                :user-ids-error="form.errors.participant_user_ids"
                :emails-error="form.errors.participant_emails"
            />
        </form>

        <div v-else-if="meeting" class="space-y-4 pt-2">
            <div class="flex items-center gap-2">
                <p class="text-foreground text-sm font-medium">{{ meeting.title }}</p>
                <AppBadge :variant="past ? 'neutral' : 'success'" size="sm">{{ past ? 'Past' : 'Upcoming' }}</AppBadge>
            </div>

            <div>
                <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Agenda</p>
                <p class="text-foreground mt-1 text-sm leading-relaxed">{{ meeting.description || 'No agenda added.' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Date & time</p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <CalendarClock class="text-muted-foreground size-3.5" />
                        <span class="text-sm"
                            >{{ formatMeetingDate(meeting.scheduled_at, timeZone) }} · {{ formatMeetingTime(meeting.scheduled_at, timeZone) }}</span
                        >
                    </div>
                </div>

                <div>
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Duration</p>
                    <div class="mt-1.5 flex items-center gap-1.5">
                        <Clock class="text-muted-foreground size-3.5" />
                        <span class="text-sm">{{ formatDuration(meeting.duration_minutes) }}</span>
                    </div>
                </div>
            </div>

            <div>
                <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Meeting link</p>
                <div class="mt-1.5">
                    <Button
                        v-if="hasJoinLink"
                        as="a"
                        :href="meeting.meeting_link!"
                        target="_blank"
                        rel="noopener noreferrer"
                        size="sm"
                        class="gap-1.5"
                    >
                        <Video class="size-3.5" />
                        Join Meeting
                    </Button>
                    <p v-else class="text-muted-foreground text-sm">No link added yet.</p>
                </div>
            </div>

            <div>
                <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Created by</p>
                <div class="mt-1.5 flex items-center gap-1.5">
                    <UserIcon class="text-muted-foreground size-3.5" />
                    <span class="text-sm">{{ meeting.creator_name ?? 'Unknown' }}</span>
                </div>
            </div>
        </div>

        <div v-if="meeting && !canManage && meeting.participants.length > 0" class="grid gap-2 pt-4">
            <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Participants</p>

            <ul class="divide-border/60 divide-y">
                <li v-for="participant in meeting.participants" :key="participant.id" class="flex items-center justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <p class="text-foreground truncate text-[13px]">{{ participant.name ?? participant.email }}</p>
                        <p v-if="participant.name" class="text-muted-foreground truncate text-[11px]">{{ participant.email }}</p>
                    </div>

                    <span v-if="participant.is_external" class="text-muted-foreground shrink-0 text-[10.5px] uppercase">Guest</span>
                </li>
            </ul>
        </div>

        <div v-if="meeting && past" class="pt-4">
            <MeetingTranscriptPanel :meeting="meeting" :can-manage="canManage" />
        </div>

        <template #footer>
            <Button v-if="!canManage" type="button" @click="handleClose(false)"> Close </Button>

            <template v-else>
                <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

                <Button type="submit" form="edit-meeting-form" :disabled="form.processing || form.title.trim().length < 2 || !form.scheduled_at">
                    <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Saving…' : 'Save changes' }}
                </Button>
            </template>
        </template>
    </AppModal>
</template>
