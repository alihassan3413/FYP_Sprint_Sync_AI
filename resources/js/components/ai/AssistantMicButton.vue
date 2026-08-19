<script setup lang="ts">
import { Loader2, Mic, Square } from 'lucide-vue-next';

/**
 * Push-to-talk control for the assistant.
 *
 * Click to start listening, click again to send. The recording is transcribed
 * server-side and submitted as an ordinary chat message, so voice and typing
 * share the same conversation.
 */

const { submit, isStreaming } = useAiAssistant();
const { setVoiceEnabled } = useVoiceOutput();

const { isSupported, isRecording, isTranscribing, error, toggleRecording, dismissError } = useVoiceInput({
    onTranscript: (text) => {
        // Talking to the assistant implies wanting to be answered aloud. The
        // speaker toggle stays in charge from here on.
        setVoiceEnabled(true);
        submit(text);
    },
});

const isBusy = computed(() => isTranscribing.value || isStreaming.value);

const label = computed(() => {
    if (isRecording.value) return 'Stop recording and send';
    if (isTranscribing.value) return 'Transcribing your recording';

    return 'Record a voice message';
});
</script>

<template>
    <div v-if="isSupported" class="relative shrink-0">
        <button
            type="button"
            :disabled="isBusy && !isRecording"
            :aria-label="label"
            :title="label"
            :class="[
                'flex size-11 shrink-0 items-center justify-center rounded-full border transition-all active:scale-95',
                'disabled:cursor-not-allowed disabled:opacity-40',
                isRecording
                    ? 'animate-pulse border-transparent bg-[#ff5f57] text-white shadow-[0_0_0_4px_rgba(255,95,87,0.25)]'
                    : 'border-white/30 text-white hover:bg-white/20',
            ]"
            @click.stop="toggleRecording"
        >
            <Loader2 v-if="isTranscribing" class="size-4 animate-spin" :stroke-width="2.2" />
            <Square v-else-if="isRecording" class="size-3.5 fill-current" :stroke-width="2.2" />
            <Mic v-else class="size-4" :stroke-width="2.2" />
        </button>

        <!-- Permission and transcription failures surface here rather than in
             the transcript, so a failed recording never becomes a message. -->
        <button
            v-if="error"
            type="button"
            class="absolute bottom-full left-1/2 z-10 mb-2 w-max max-w-[240px] -translate-x-1/2 rounded-xl bg-black/80 px-3 py-1.5 text-left text-[11px] leading-snug text-white/90 shadow-lg backdrop-blur"
            aria-live="polite"
            @click.stop="dismissError"
        >
            {{ error }}
        </button>
    </div>
</template>
