<script setup lang="ts">
import { Volume2, VolumeX } from 'lucide-vue-next';

/** Controls whether assistant replies are read aloud by the browser. */

const { isSupported, isEnabled, isSpeaking, isPreparing, toggleVoiceOutput } = useVoiceOutput();

const label = computed(() => (isEnabled.value ? 'Mute spoken replies' : 'Read replies aloud'));
</script>

<template>
    <button
        v-if="isSupported"
        type="button"
        :aria-label="label"
        :aria-pressed="isEnabled"
        :title="label"
        :class="[
            'grid size-6 place-items-center rounded-full transition',
            isEnabled ? 'text-white hover:bg-white/10' : 'text-white/40 hover:bg-white/10 hover:text-white/70',
            (isSpeaking || isPreparing) && 'animate-pulse',
        ]"
        @click="toggleVoiceOutput"
    >
        <Volume2 v-if="isEnabled" class="size-3.5" :stroke-width="2.2" />
        <VolumeX v-else class="size-3.5" :stroke-width="2.2" />
    </button>
</template>
