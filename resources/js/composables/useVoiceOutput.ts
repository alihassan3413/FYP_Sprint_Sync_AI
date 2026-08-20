import { getCsrfToken } from '@/lib/csrf';
import { ref } from 'vue';

/**
 * Speaks assistant replies aloud.
 *
 * Audio comes from the server's neural text-to-speech endpoint, which sounds
 * far better than the voices a browser ships with. Synthesis costs about 1.5
 * seconds per request no matter how short the text, so replies are split into
 * chunks: the first sentence is dispatched the instant it is complete, and
 * later chunks are batched and synthesised while the previous one is still
 * playing. Only the first chunk's latency is ever heard.
 *
 * If the endpoint is unavailable or fails, playback silently degrades to the
 * browser's own speech synthesis for the rest of the session. The assistant
 * always talks; it just occasionally sounds less polished.
 */

const STORAGE_KEY = 'assistant.voice.enabled';

const SENTENCE_BOUNDARY = /([.!?]+["')\]]*\s+|\n+)/;

/**
 * Later chunks are batched, because each costs a round trip and they are
 * synthesised while the previous one is still playing — nobody is waiting on
 * them. The first utterance is never batched: see {@see speakChunk}.
 */
const CHUNK_TARGET = 340;

/**
 * How far the first utterance may run without a sentence ending before it is
 * cut at a clause instead. A reply that opens with a long run-on would
 * otherwise stay silent until the whole thing had streamed in.
 */
const FIRST_CHUNK_CLAUSE_LIMIT = 140;

const CLAUSE_BOUNDARY = /[,;:]\s+/g;

/** Server-side cap on a single utterance. Keep in sync with assistant.speech. */
const MAX_CHUNK_CHARACTERS = 1000;

/** How far ahead of playback to synthesise. Bounds cost on long replies. */
const MAX_PREFETCH = 3;

const browserSpeechSupported = typeof window !== 'undefined' && 'speechSynthesis' in window;

/** Best-first: the browser default is usually the worst voice installed. */
const BROWSER_VOICE_PREFERENCES = [
    /Google US English/i,
    /Microsoft.*(Aria|Jenny|Guy|Michelle).*(Online|Natural)/i,
    /Natural/i,
    /\b(Samantha|Ava|Allison|Susan|Zoe|Serena)\b/i,
    /Google/i,
];

function readStoredPreference(): boolean {
    if (typeof window === 'undefined') return false;

    return window.localStorage.getItem(STORAGE_KEY) === '1';
}

// ---- Module-level singleton state, mirroring useAiAssistant ----
const isEnabled = ref(readStoredPreference());
const isSpeaking = ref(false);
/** True between asking for audio and hearing it — drives the "preparing" hint. */
const isPreparing = ref(false);
/** Degrades to 'browser' for the rest of the session once the API fails. */
const engine = ref<'neural' | 'browser'>('neural');

let pendingBuffer = '';
let hasSpokenThisReply = false;

/** Utterances awaiting playback, each already synthesising. */
type Utterance = { text: string; audio: Promise<Blob | null> };

let queue: Utterance[] = [];
let backlog: string[] = [];
let isDraining = false;
let currentAudio: HTMLAudioElement | null = null;
let controller: AbortController | null = null;

/**
 * Strip Markdown so the voice reads prose instead of punctuation. The panel
 * renders the same text as Markdown; this is the spoken counterpart.
 */
export function toSpeakableText(markdown: string): string {
    return markdown
        .replace(/```[\s\S]*?```/g, ' code block. ')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/https?:\/\/\S+/g, 'link')
        .replace(/(\*\*|__)(.*?)\1/g, '$2')
        .replace(/(\*|_)(.*?)\1/g, '$2')
        .replace(/^#{1,6}\s+/gm, '')
        .replace(/^\s*>\s?/gm, '')
        .replace(/^\s*[-*+]\s+/gm, '')
        .replace(/^\s*\d+\.\s+/gm, '')
        .replace(/[⚠️✅❌•]/g, '')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{2,}/g, '\n')
        .trim();
}

// ---- Browser speech synthesis (fallback) ----

function pickBrowserVoice(): SpeechSynthesisVoice | null {
    if (!browserSpeechSupported) return null;

    const english = window.speechSynthesis.getVoices().filter((v) => v.lang.startsWith('en'));

    if (english.length === 0) return null;

    for (const pattern of BROWSER_VOICE_PREFERENCES) {
        const match = english.find((v) => pattern.test(v.name));
        if (match) return match;
    }

    return english.find((v) => v.default) ?? english[0];
}

// Voices load asynchronously in Chrome; the first call often returns [].
if (browserSpeechSupported) {
    window.speechSynthesis.getVoices();
    window.speechSynthesis.addEventListener('voiceschanged', () => pickBrowserVoice());
}

function speakWithBrowser(text: string): Promise<void> {
    return new Promise((resolve) => {
        if (!browserSpeechSupported || !text) {
            resolve();

            return;
        }

        const utterance = new SpeechSynthesisUtterance(text);
        const voice = pickBrowserVoice();

        if (voice) utterance.voice = voice;
        utterance.lang = voice?.lang ?? 'en-US';
        utterance.rate = 1.05;

        utterance.onend = () => resolve();
        utterance.onerror = () => resolve();

        window.speechSynthesis.speak(utterance);
    });
}

// ---- Neural speech (primary) ----

async function synthesize(text: string, signal: AbortSignal): Promise<Blob | null> {
    try {
        const response = await fetch('/assistant/voice/speak', {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ text: text.slice(0, MAX_CHUNK_CHARACTERS) }),
        });

        if (!response.ok) {
            // 503 means speech is switched off server-side; anything else is a
            // transient failure. Either way this session finishes on the
            // browser voice rather than retrying every chunk.
            engine.value = 'browser';

            return null;
        }

        return await response.blob();
    } catch (e) {
        if ((e as Error)?.name === 'AbortError') return null;

        console.warn('[assistant] speech synthesis failed, using browser voice:', e);
        engine.value = 'browser';

        return null;
    }
}

function playBlob(blob: Blob): Promise<void> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(blob);
        const audio = new Audio(url);
        currentAudio = audio;

        const done = () => {
            URL.revokeObjectURL(url);

            if (currentAudio === audio) currentAudio = null;

            resolve();
        };

        audio.onended = done;
        audio.onerror = done;

        audio.play().catch(() => {
            // Autoplay policy, or a codec the browser will not decode.
            engine.value = 'browser';
            done();
        });
    });
}

// ---- Queue ----

function enqueue(rawText: string) {
    const text = toSpeakableText(rawText);

    if (!text) return;

    backlog.push(text);
    fillQueue();
    void drain();
}

/** Keep a bounded number of chunks synthesising ahead of playback. */
function fillQueue() {
    while (backlog.length > 0 && queue.length < MAX_PREFETCH) {
        const text = backlog.shift()!;

        if (engine.value === 'browser') {
            queue.push({ text, audio: Promise.resolve(null) });

            continue;
        }

        controller ??= new AbortController();
        queue.push({ text, audio: synthesize(text, controller.signal) });
    }
}

async function drain() {
    if (isDraining) return;

    isDraining = true;

    try {
        while (queue.length > 0) {
            const utterance = queue.shift()!;

            // Freeing a slot lets the next chunk start synthesising now,
            // while this one is still being spoken.
            fillQueue();

            isPreparing.value = true;
            const blob = await utterance.audio;
            isPreparing.value = false;

            if (!isEnabled.value) break;

            isSpeaking.value = true;

            if (blob) {
                await playBlob(blob);
            } else {
                await speakWithBrowser(utterance.text);
            }
        }
    } finally {
        isDraining = false;
        isPreparing.value = false;
        isSpeaking.value = queue.length > 0;
    }
}

// ---- Public surface ----

/**
 * Feed a streaming chunk of the reply. Whole sentences are dispatched as they
 * arrive; the remainder waits for the next chunk or for {@see flushSpeech}.
 */
function speakChunk(chunk: string) {
    if (!isEnabled.value) return;

    pendingBuffer += chunk;

    for (;;) {
        const match = pendingBuffer.match(SENTENCE_BOUNDARY);

        if (match?.index === undefined) break;

        const end = match.index + match[0].length;

        // Hold short sentences back so later requests carry a full breath of
        // text rather than costing a round trip each. Never the first one:
        // time-to-first-word is the whole of what a listener perceives as
        // responsiveness, and most replies are shorter than one batch, so
        // batching the opener meant waiting for the entire reply.
        if (hasSpokenThisReply && end < CHUNK_TARGET && pendingBuffer.length < CHUNK_TARGET) break;

        enqueue(pendingBuffer.slice(0, end));
        pendingBuffer = pendingBuffer.slice(end);
        hasSpokenThisReply = true;
    }

    // Nothing spoken yet and no sentence in sight: start on a clause so the
    // voice comes in on time even when the reply opens with a run-on.
    if (!hasSpokenThisReply && pendingBuffer.length > FIRST_CHUNK_CLAUSE_LIMIT) {
        CLAUSE_BOUNDARY.lastIndex = 0;
        let cut = 0;

        for (let m = CLAUSE_BOUNDARY.exec(pendingBuffer); m !== null; m = CLAUSE_BOUNDARY.exec(pendingBuffer)) {
            const candidate = m.index + m[0].length;

            if (candidate > FIRST_CHUNK_CLAUSE_LIMIT) break;

            cut = candidate;
        }

        if (cut > 0) {
            enqueue(pendingBuffer.slice(0, cut));
            pendingBuffer = pendingBuffer.slice(cut);
            hasSpokenThisReply = true;
        }
    }

    // A long clause with no terminator (a list, a run-on) would otherwise stay
    // silent until the reply finished.
    if (pendingBuffer.length > MAX_CHUNK_CHARACTERS) {
        const cut = pendingBuffer.lastIndexOf(' ');
        enqueue(pendingBuffer.slice(0, cut > 0 ? cut : pendingBuffer.length));
        pendingBuffer = cut > 0 ? pendingBuffer.slice(cut) : '';
        hasSpokenThisReply = true;
    }
}

/** Speak whatever is left in the buffer. Call when the reply is complete. */
function flushSpeech() {
    const remainder = pendingBuffer;
    pendingBuffer = '';
    hasSpokenThisReply = false;

    if (!isEnabled.value || !remainder.trim()) return;

    enqueue(remainder);
}

/** Speak a complete string in one go, discarding anything queued. */
function speak(text: string) {
    if (!isEnabled.value) return;

    stopSpeaking();
    enqueue(text);
}

function stopSpeaking() {
    pendingBuffer = '';
    hasSpokenThisReply = false;
    backlog = [];
    queue = [];

    controller?.abort();
    controller = null;

    if (currentAudio) {
        currentAudio.pause();
        currentAudio = null;
    }

    if (browserSpeechSupported) {
        window.speechSynthesis.cancel();
    }

    isSpeaking.value = false;
    isPreparing.value = false;
}

function setVoiceEnabled(value: boolean) {
    isEnabled.value = value;
    window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');

    if (!value) stopSpeaking();
}

function toggleVoiceOutput() {
    setVoiceEnabled(!isEnabled.value);
}

export function useVoiceOutput() {
    return {
        // Neural speech needs no browser support; only the fallback does.
        isSupported: true,
        isEnabled,
        isSpeaking,
        isPreparing,
        engine,
        speak,
        speakChunk,
        flushSpeech,
        stopSpeaking,
        setVoiceEnabled,
        toggleVoiceOutput,
    };
}
