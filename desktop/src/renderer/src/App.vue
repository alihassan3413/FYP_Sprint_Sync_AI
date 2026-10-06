<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

import type { AppState } from '../../shared/types';
import SignIn from './components/SignIn.vue';
import TimerPanel from './components/TimerPanel.vue';

const state = ref<AppState | null>(null);
let unsubscribe: (() => void) | null = null;

onMounted(async () => {
    unsubscribe = window.sprintsync.onState((next) => (state.value = next));
    state.value = await window.sprintsync.getState();
});

onBeforeUnmount(() => unsubscribe?.());
</script>

<template>
    <main class="shell">
        <header class="brand">
            <span class="logo">SP</span>
            <span class="name">SprintSync</span>
            <span v-if="state?.phase === 'ready' && !state.online" class="offline" data-testid="offline">Offline</span>
        </header>

        <p v-if="!state || state.phase === 'loading'" class="muted loading">Loading…</p>
        <SignIn v-else-if="state.phase === 'signed-out'" :state="state" />
        <TimerPanel v-else :state="state" />
    </main>
</template>

<style scoped>
.shell {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
}

.logo {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: var(--primary);
    color: var(--primary-foreground);
    font-size: 12px;
}

.offline {
    margin-left: auto;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 999px;
    background: var(--muted);
    color: var(--muted-foreground);
}

.loading {
    text-align: center;
    margin-top: 40px;
}
</style>
