<script setup lang="ts">
import { AlertTriangle, CheckCircle2, FileText, Loader2, Upload } from 'lucide-vue-next';

import type { Meeting } from '@/lib/meetings';

const props = defineProps<{
    meeting: Meeting;
    canManage: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const transcript = computed(() => props.meeting.transcript);

const isPending = computed(() => {
    const status = transcript.value?.status;
    return status === 'queued' || status === 'processing';
});

const showManualEntry = ref(false);

const recordingForm = useForm<{ recording: File | null }>({ recording: null });
const manualForm = useForm({ text: '' });

function routeFor(action: 'recording' | 'manual' | 'retry') {
    return workspaceRoute(`workspace.projects.meetings.transcript.${action}`, {
        project: props.meeting.project_id,
        meeting: props.meeting.id,
    });
}

function onRecordingSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (!file) return;

    recordingForm.recording = file;
    recordingForm.post(routeFor('recording'), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            recordingForm.reset();
            input.value = '';
        },
    });
}

function submitManual() {
    manualForm.post(routeFor('manual'), {
        preserveScroll: true,
        onSuccess: () => {
            manualForm.reset();
            showManualEntry.value = false;
        },
    });
}

function retry() {
    router.post(routeFor('retry'), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="bg-muted/20 rounded-xl border p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <FileText class="text-muted-foreground size-4" />
                <h4 class="text-sm font-medium">Transcript</h4>

                <span
                    v-if="transcript"
                    class="rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                    :class="
                        transcript.status === 'completed'
                            ? 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                            : transcript.status === 'failed'
                              ? 'border-destructive/40 text-destructive'
                              : 'border-border text-muted-foreground'
                    "
                >
                    {{ transcript.status_label }}
                </span>
            </div>

            <div v-if="canManage" class="flex items-center gap-1.5">
                <label
                    class="border-input hover:bg-muted/50 inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-medium transition-colors"
                >
                    <Loader2 v-if="recordingForm.processing" class="size-3 animate-spin" />
                    <Upload v-else class="size-3" />
                    {{ transcript?.has_audio ? 'Replace recording' : 'Upload recording' }}
                    <input type="file" class="hidden" accept="audio/*,video/mp4,video/webm" @change="onRecordingSelected" />
                </label>

                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground text-[11px] underline underline-offset-2"
                    @click="showManualEntry = !showManualEntry"
                >
                    Enter manually
                </button>
            </div>
        </div>

        <p v-if="recordingForm.errors.recording" class="text-destructive mt-2 text-xs">{{ recordingForm.errors.recording }}</p>

        <div v-if="isPending" class="text-muted-foreground mt-3 flex items-center gap-2 text-xs">
            <Loader2 class="size-3.5 animate-spin" />
            <span>Transcription is running. This page will show the transcript once it finishes.</span>
        </div>

        <div v-else-if="transcript?.status === 'failed'" class="mt-3">
            <p class="text-destructive flex items-start gap-2 text-xs">
                <AlertTriangle class="mt-px size-3.5 shrink-0" />
                <span>{{ transcript.failure_reason }}</span>
            </p>

            <Button v-if="canManage && transcript.has_audio" size="sm" variant="outline" class="mt-2 h-7 text-xs" @click="retry"> Try again </Button>
        </div>

        <div v-else-if="transcript?.status === 'completed'" class="mt-3">
            <div
                v-if="transcript.is_low_confidence"
                class="mb-3 flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-2.5 text-[11px] text-amber-800 dark:text-amber-300"
            >
                <AlertTriangle class="mt-px size-3.5 shrink-0" />
                <span>
                    Low audio quality — this transcript may contain errors ({{ transcript.confidence }}% confidence). Review it before relying on it.
                </span>
            </div>

            <p class="text-muted-foreground mb-2 flex items-center gap-1.5 text-[11px]">
                <CheckCircle2 class="size-3 text-emerald-500" />
                {{ transcript.source === 'manual' ? 'Entered manually' : 'Transcribed automatically' }}
            </p>

            <p class="text-foreground max-h-64 overflow-y-auto text-xs leading-relaxed whitespace-pre-line">{{ transcript.text }}</p>
        </div>

        <p v-else class="text-muted-foreground mt-3 text-xs">
            No recording yet. Upload the meeting audio and SprintSync will transcribe it automatically.
        </p>

        <form v-if="showManualEntry && canManage" class="mt-3 border-t pt-3" @submit.prevent="submitManual">
            <textarea
                v-model="manualForm.text"
                rows="6"
                placeholder="Paste the meeting transcript here…"
                class="border-input bg-background focus:ring-ring/40 w-full rounded-lg border p-2.5 text-xs transition-colors focus:ring-2 focus:outline-none"
            />
            <p v-if="manualForm.errors.text" class="text-destructive mt-1 text-xs">{{ manualForm.errors.text }}</p>

            <div class="mt-2 flex items-center gap-2">
                <Button type="submit" size="sm" class="h-7 text-xs" :disabled="manualForm.processing">
                    <Loader2 v-if="manualForm.processing" class="mr-1.5 size-3 animate-spin" />
                    Save transcript
                </Button>
                <Button type="button" size="sm" variant="ghost" class="h-7 text-xs" @click="showManualEntry = false">Cancel</Button>
            </div>
        </form>
    </div>
</template>
