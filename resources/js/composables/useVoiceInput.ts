import { getCsrfToken } from '@/lib/csrf';
import { ref } from 'vue';

/**
 * Captures microphone audio and turns it into text.
 *
 * Recording happens in the browser with MediaRecorder; the clip is posted to
 * /assistant/voice/transcribe, which runs it through the same transcription
 * provider the Meetings module uses. Nothing is stored — the server deletes the
 * upload as soon as it has the transcript.
 */

/** Runaway guard: a forgotten open mic should not upload a ten-minute file. */
const MAX_RECORDING_MS = 60_000;

/** Below this a WebM clip is header-only — the user tapped rather than spoke. */
const MIN_BLOB_BYTES = 1200;

/** How long the mic must stay quiet after speech before the clip is sent. */
const SILENCE_HOLD_MS = 2_500;

/** Root-mean-square level below which a frame counts as silence. */
const SPEECH_RMS_THRESHOLD = 0.015;

const SILENCE_POLL_MS = 100;

const MIME_PREFERENCES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];

export interface VoiceInputOptions {
    /** Called with the transcript once the recording has been transcribed. */
    onTranscript: (text: string) => void;
}

function isMediaRecorderSupported(): boolean {
    return typeof window !== 'undefined' && typeof window.MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia;
}

function pickMimeType(): string {
    return MIME_PREFERENCES.find((type) => MediaRecorder.isTypeSupported(type)) ?? '';
}

/** Whisper picks its decoder from the extension, so it has to match the blob. */
function filenameFor(mimeType: string): string {
    if (mimeType.includes('ogg')) return 'speech.ogg';
    if (mimeType.includes('mp4')) return 'speech.mp4';

    return 'speech.webm';
}

async function transcribe(blob: Blob, mimeType: string): Promise<string> {
    const form = new FormData();
    form.append('audio', blob, filenameFor(mimeType));

    const response = await fetch('/assistant/voice/transcribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: form,
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message ?? 'That recording could not be transcribed.');
    }

    return String(body.text ?? '').trim();
}

export function useVoiceInput(options: VoiceInputOptions) {
    const isSupported = isMediaRecorderSupported();
    const isRecording = ref(false);
    const isTranscribing = ref(false);
    const error = ref<string | null>(null);

    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let chunks: Blob[] = [];
    let maxDurationTimer: ReturnType<typeof setTimeout> | null = null;
    let audioContext: AudioContext | null = null;
    let analyser: AnalyserNode | null = null;
    let silenceTimer: ReturnType<typeof setInterval> | null = null;
    let lastSpeechAt = 0;
    let hasHeardSpeech = false;
    // Set when the user cancels, so the stop handler discards the audio
    // instead of transcribing it.
    let discardRecording = false;

    function stopSilenceDetection() {
        if (silenceTimer !== null) {
            clearInterval(silenceTimer);
            silenceTimer = null;
        }

        analyser = null;
        hasHeardSpeech = false;

        void audioContext?.close().catch(() => undefined);
        audioContext = null;
    }

    /**
     * Watches the live input level and stops the recording once the user has
     * spoken and then gone quiet, so they do not have to press stop themselves.
     * Silence before the first word is ignored — otherwise it would cut off
     * someone still gathering their thoughts.
     */
    function startSilenceDetection(source: MediaStream) {
        const AudioContextCtor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

        if (!AudioContextCtor) return;

        try {
            audioContext = new AudioContextCtor();
            analyser = audioContext.createAnalyser();
            analyser.fftSize = 2048;
            audioContext.createMediaStreamSource(source).connect(analyser);
        } catch (e) {
            console.error('[assistant] silence detection unavailable:', e);
            stopSilenceDetection();

            return;
        }

        const samples = new Float32Array(analyser.fftSize);
        hasHeardSpeech = false;
        lastSpeechAt = Date.now();

        silenceTimer = setInterval(() => {
            if (!analyser) return;

            analyser.getFloatTimeDomainData(samples);

            let sum = 0;
            for (let i = 0; i < samples.length; i++) {
                sum += samples[i] * samples[i];
            }

            if (Math.sqrt(sum / samples.length) >= SPEECH_RMS_THRESHOLD) {
                hasHeardSpeech = true;
                lastSpeechAt = Date.now();

                return;
            }

            if (hasHeardSpeech && Date.now() - lastSpeechAt >= SILENCE_HOLD_MS) {
                stopRecording();
            }
        }, SILENCE_POLL_MS);
    }

    function releaseMicrophone() {
        stopSilenceDetection();

        if (maxDurationTimer !== null) {
            clearTimeout(maxDurationTimer);
            maxDurationTimer = null;
        }

        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        recorder = null;
        isRecording.value = false;
    }

    async function handleRecordingStopped(mimeType: string) {
        const blob = new Blob(chunks, { type: mimeType || 'audio/webm' });
        chunks = [];

        releaseMicrophone();

        if (discardRecording) {
            discardRecording = false;

            return;
        }

        if (blob.size < MIN_BLOB_BYTES) {
            error.value = 'That was too short to hear. Hold the button while you speak.';

            return;
        }

        isTranscribing.value = true;

        try {
            const text = await transcribe(blob, mimeType);

            if (!text) {
                error.value = 'I did not catch that. Please try again.';

                return;
            }

            options.onTranscript(text);
        } catch (e) {
            console.error('[assistant] transcription failed:', e);
            error.value = e instanceof Error ? e.message : 'Voice input failed. Please try again.';
        } finally {
            isTranscribing.value = false;
        }
    }

    async function startRecording() {
        if (!isSupported || isRecording.value || isTranscribing.value) return;

        error.value = null;
        discardRecording = false;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
            });
        } catch (e) {
            console.error('[assistant] microphone access denied:', e);
            error.value = 'Microphone access is blocked. Enable it in your browser settings.';

            return;
        }

        const mimeType = pickMimeType();
        recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        chunks = [];

        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) chunks.push(event.data);
        };
        recorder.onstop = () => void handleRecordingStopped(mimeType);

        recorder.start();
        isRecording.value = true;

        startSilenceDetection(stream);

        maxDurationTimer = setTimeout(() => stopRecording(), MAX_RECORDING_MS);
    }

    function stopRecording() {
        if (recorder?.state === 'recording') {
            recorder.stop();
        } else {
            releaseMicrophone();
        }
    }

    /** Stop listening and throw the audio away without transcribing it. */
    function cancelRecording() {
        discardRecording = true;
        stopRecording();
    }

    function toggleRecording() {
        if (isRecording.value) {
            stopRecording();
        } else {
            void startRecording();
        }
    }

    function dismissError() {
        error.value = null;
    }

    return {
        isSupported,
        isRecording,
        isTranscribing,
        error,
        startRecording,
        stopRecording,
        cancelRecording,
        toggleRecording,
        dismissError,
    };
}
