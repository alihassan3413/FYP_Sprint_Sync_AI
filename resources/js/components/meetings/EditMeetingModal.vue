<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

import { toDateTimeLocalValue, type Meeting } from '@/lib/meetings';

const props = defineProps<{
    open: boolean;
    meeting: Meeting | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    updated: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm<{
    title: string;
    description: string;
    scheduled_at: string;
    duration_minutes: string;
    meeting_link: string;
}>({
    title: '',
    description: '',
    scheduled_at: '',
    duration_minutes: '30',
    meeting_link: '',
});

watch(
    () => props.meeting,
    (meeting) => {
        form.clearErrors();
        form.title = meeting?.title ?? '';
        form.description = meeting?.description ?? '';
        form.scheduled_at = meeting ? toDateTimeLocalValue(meeting.scheduled_at) : '';
        form.duration_minutes = meeting ? String(meeting.duration_minutes) : '30';
        form.meeting_link = meeting?.meeting_link ?? '';
    },
    { immediate: true },
);

function submit() {
    if (!props.meeting) return;

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
    <AppModal :open="open" title="Edit meeting" size="md" @update:open="handleClose">
        <form v-if="meeting" id="edit-meeting-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput id="edit-meeting-title" v-model="form.title" label="Title" :error="form.errors.title" required autofocus autocomplete="off" />

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
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

            <Button type="submit" form="edit-meeting-form" :disabled="form.processing || form.title.trim().length < 2 || !form.scheduled_at">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Saving…' : 'Save changes' }}
            </Button>
        </template>
    </AppModal>
</template>
