<script setup lang="ts">
import { ref } from 'vue';

import type { AppState } from '../../../shared/types';

defineProps<{ state: AppState }>();

const email = ref('');
const password = ref('');

async function submit() {
    await window.sprintsync.signIn(email.value.trim(), password.value);
    /* The password never stays in the window after a sign-in attempt. */
    password.value = '';
}
</script>

<template>
    <form class="card form" @submit.prevent="submit">
        <div>
            <h1>Sign in</h1>
            <p class="muted small">Use your SprintSync email and password.</p>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required data-testid="email" />
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" v-model="password" type="password" autocomplete="current-password" required data-testid="password" />
        </div>
        <p v-if="state.signInError" class="error" role="alert" data-testid="sign-in-error">{{ state.signInError }}</p>
        <button class="btn btn-primary" type="submit" :disabled="state.busy" data-testid="sign-in">
            {{ state.busy ? 'Signing in…' : 'Sign in' }}
        </button>
        <p class="muted small server">{{ state.serverUrl }}</p>
    </form>
</template>

<style scoped>
.form {
    display: grid;
    gap: 14px;
}

h1 {
    margin: 0 0 2px;
    font-size: 18px;
}

p {
    margin: 0;
}

.error {
    color: var(--danger);
    font-size: 13px;
}

.server {
    text-align: center;
    word-break: break-all;
}
</style>
