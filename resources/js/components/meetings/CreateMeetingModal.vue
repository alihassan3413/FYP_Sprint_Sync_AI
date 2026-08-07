<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

const props = defineProps<{
    open: boolean;
    projectId: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
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

function submit() {
    form.post(workspaceRoute('workspace.projects.meetings.store', { project: props.projectId }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('created');
            reset();
            emit('update:open', false);
        },
    });
}

function reset() {
    form.reset();
    form.clearErrors();
}

function handleClose(value: boolean) {
    if (form.processing) return;

    if (!value) {
        reset();
    }

    emit('update:open', value);
}
</script>

<template>
    <AppModal :open="open" title="Schedule meeting" description="Add a meeting to this project." size="md" @update:open="handleClose">
        <form id="create-meeting-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="meeting-title"
                v-model="form.title"
                label="Title"
                placeholder="e.g. Sprint planning"
                :error="form.errors.title"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="meeting-description" class="text-sm font-medium">
                    Agenda <span class="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <Textarea id="meeting-description" v-model="form.description" placeholder="What's this meeting about?" rows="3" />
                <InputError :message="form.errors.description" />
            </div>

            <AppFormInput
                id="meeting-scheduled-at"
                v-model="form.scheduled_at"
                type="datetime-local"
                label="Date & time"
                :error="form.errors.scheduled_at"
                required
            />

            <div class="grid grid-cols-2 gap-4">
                <AppFormInput
                    id="meeting-duration"
                    v-model="form.duration_minutes"
                    type="number"
                    label="Duration (min)"
                    :error="form.errors.duration_minutes"
                    required
                />

                <AppFormInput
                    id="meeting-link"
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

            <Button type="submit" form="create-meeting-form" :disabled="form.processing || form.title.trim().length < 2 || !form.scheduled_at">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Scheduling…' : 'Schedule meeting' }}
            </Button>
        </template>
    </AppModal>
</template>
