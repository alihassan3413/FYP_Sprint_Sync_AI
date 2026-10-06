<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

import { elapsedSeconds, formatClock, formatDuration, lunchSeconds } from '../../../shared/time';
import type { AppState } from '../../../shared/types';

const props = defineProps<{ state: AppState }>();

/** The app's narrow bridge (see preload); templates can't reach window directly. */
const app = window.sprintsync;

/* The clock is computed here from SprintSync's timestamps; nothing is fetched every second. */
const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(tick));

const timer = computed(() => props.state.timer);
const elapsed = computed(() => elapsedSeconds(timer.value, now.value, props.state.clockOffsetMs));
const tasks = computed(() => props.state.projects.find((project) => project.public_id === props.state.selection.project)?.tasks ?? []);
const settingsOpen = ref(false);

const lunch = computed(() => lunchSeconds(timer.value, now.value, props.state.clockOffsetMs));
const lunchOver = computed(() => !!props.state.policy?.lunch_allowance_seconds && lunch.value > props.state.policy.lunch_allowance_seconds);

const pendingIdle = computed(() => props.state.idlePeriods.filter((period) => period.decision === 'pending'));
const reviewOpen = ref(false);
const reasons = ref<Record<string, string>>({});

async function keep(idlePeriod: string) {
    const reason = reasons.value[idlePeriod]?.trim();

    if (reason) await app.keepIdle(idlePeriod, reason);
}

const timeOfDay = (iso: string) => new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });

/** Plain words about the workspace's rules; nothing is hidden from the person tracking. */
const policyNotice = computed(() => {
    const policy = props.state.policy;
    const parts: string[] = [];

    if (policy?.idle_enabled && policy.idle_seconds) parts.push(`timers pause after ${Math.round(policy.idle_seconds / 60)} minutes of inactivity`);
    if (policy?.integrity_enabled) parts.push('activity integrity checks are enabled by your workspace');

    return parts.length ? `This workspace: ${parts.join('; ')}. SprintSync does not record screenshots, keystrokes, websites or applications.` : null;
});

function selectProject(event: Event) {
    void window.sprintsync.select((event.target as HTMLSelectElement).value || null, null);
}

function selectTask(event: Event) {
    void window.sprintsync.select(props.state.selection.project, (event.target as HTMLSelectElement).value || null);
}

function selectWorkspace(event: Event) {
    void window.sprintsync.selectWorkspace((event.target as HTMLSelectElement).value);
}

function toggleLaunchAtLogin(event: Event) {
    void window.sprintsync.setLaunchAtLogin((event.target as HTMLInputElement).checked);
}
</script>

<template>
    <section class="panel">
        <p v-if="!state.online" class="banner warning" data-testid="offline-banner">Offline — we'll sync when you're back.</p>
        <p v-if="state.pending > 0" class="banner muted small" data-testid="pending">
            {{ state.pending }} change{{ state.pending === 1 ? '' : 's' }} waiting to sync
        </p>
        <button v-if="state.notice" type="button" class="banner notice" :class="state.notice.kind" data-testid="notice" @click="app.dismissNotice()">
            {{ state.notice.text }}
        </button>

        <div v-if="state.workspaces.length > 1" class="field">
            <label for="workspace">Workspace</label>
            <select id="workspace" :value="state.workspace ?? ''" :disabled="!!timer" @change="selectWorkspace">
                <option v-for="workspace in state.workspaces" :key="workspace.slug" :value="workspace.slug">{{ workspace.name }}</option>
            </select>
        </div>

        <div v-if="!state.workspace" class="card muted">You're not in a workspace where you can track time.</div>
        <div v-else-if="!state.hasProfile" class="card muted" data-testid="no-profile">
            Your account isn't linked to a Team profile in this workspace yet. Ask the workspace owner to link it.
        </div>

        <!-- Lunch: a paid break, its own state -->
        <div v-else-if="timer && timer.state === 'lunch'" class="card timer lunch" data-testid="lunch">
            <p class="project">Lunch break</p>
            <p class="muted task">Paid break</p>
            <p class="clock" :class="lunchOver && 'over'" data-testid="lunch-clock">{{ formatClock(lunch) }}</p>
            <p v-if="state.policy?.lunch_allowance_seconds" class="muted small">
                Allowed {{ formatDuration(state.policy.lunch_allowance_seconds) }}<template v-if="lunchOver"> · the extra goes to the owner to decide</template>
            </p>
            <button class="btn btn-primary" type="button" data-testid="end-lunch" @click="app.endLunch()">End lunch</button>
        </div>

        <!-- Running or paused -->
        <div v-else-if="timer" class="card timer" :class="timer.state" data-testid="timer">
            <div class="what">
                <p class="project">{{ timer.project.name }}</p>
                <p v-if="timer.task" class="muted task">{{ timer.task.title }}</p>
            </div>
            <p class="clock" data-testid="clock">{{ formatClock(elapsed) }}</p>
            <p v-if="timer.state === 'paused' && timer.pause_reason === 'idle'" class="status" data-testid="idle-paused">
                Timer paused · {{ state.policy?.idle_seconds ? Math.round(state.policy.idle_seconds / 60) : '' }} minutes inactivity
            </p>
            <p v-else-if="timer.state === 'paused'" class="status" data-testid="paused">Paused</p>
            <div class="actions" :class="{ three: timer.state === 'running' && state.policy?.lunch_enabled }">
                <button v-if="timer.state === 'running' && state.policy?.lunch_enabled" class="btn" type="button" data-testid="lunch-start" @click="app.startLunch()">Lunch</button>
                <button v-if="timer.state === 'running'" class="btn" type="button" data-testid="pause" @click="app.pause()">Pause</button>
                <button v-else class="btn" type="button" data-testid="resume" @click="app.resume()">Resume</button>
                <button class="btn btn-primary" type="button" data-testid="stop" @click="app.stop()">Stop</button>
            </div>
            <button v-if="pendingIdle.length" class="link review" type="button" data-testid="review-idle" @click="reviewOpen = !reviewOpen">Review idle time</button>
        </div>

        <!-- Idle time: keep as work (with a reason) or don't count it -->
        <div v-if="reviewOpen && pendingIdle.length" class="card idle" data-testid="idle-review">
            <div v-for="period in pendingIdle" :key="period.public_id" class="idle-row">
                <p class="small">
                    <strong>{{ timeOfDay(period.started_at) }}{{ period.ended_at ? ` – ${timeOfDay(period.ended_at)}` : '' }}</strong>
                    <span class="muted"><template v-if="period.ended_at"> · {{ formatDuration(period.seconds) }}</template> without activity</span>
                </p>
                <form class="keep" @submit.prevent="keep(period.public_id)">
                    <input v-model="reasons[period.public_id]" placeholder="What was it? e.g. Client phone call" maxlength="500" data-testid="idle-reason" />
                    <button class="btn" type="submit" :disabled="!reasons[period.public_id]?.trim() || state.busy">Keep as work</button>
                </form>
                <button v-if="period.ended_at" class="link" type="button" @click="app.dropIdle(period.public_id)">Don't count it</button>
            </div>
        </div>

        <!-- Ready -->
        <form v-else class="card ready" @submit.prevent="app.start()">
            <div class="field">
                <label for="project">Project</label>
                <select id="project" :value="state.selection.project ?? ''" required data-testid="project" @change="selectProject">
                    <option value="" disabled>Select project</option>
                    <option v-for="project in state.projects" :key="project.public_id" :value="project.public_id">{{ project.name }}</option>
                </select>
            </div>
            <div class="field">
                <label for="task">Task <span class="muted">(optional)</span></label>
                <select id="task" :value="state.selection.task ?? ''" :disabled="!tasks.length" data-testid="task" @change="selectTask">
                    <option value="">No task</option>
                    <option v-for="task in tasks" :key="task.public_id" :value="task.public_id">{{ task.title }}</option>
                </select>
            </div>
            <button class="btn btn-primary" type="submit" :disabled="state.busy || !state.selection.project || !state.online" data-testid="start">
                {{ state.busy ? 'Starting…' : 'Start timer' }}
            </button>
            <p v-if="!state.projects.length" class="muted small">You aren't on any projects yet.</p>
        </form>

        <div v-if="state.workspace && state.hasProfile" class="today" data-testid="today">
            <div class="row">
                <span class="muted">Today · worked</span>
                <strong>{{ formatDuration(state.today?.worked ?? state.todaySeconds) }}</strong>
            </div>
            <div v-if="state.today && state.today.compensated !== state.today.worked" class="row small">
                <span class="muted">Paid lunch {{ formatDuration(state.today.lunch_paid) }} · Paid time</span>
                <span>{{ formatDuration(state.today.compensated) }}</span>
            </div>
        </div>

        <p v-if="policyNotice" class="muted small notice-policy" data-testid="policy-notice">{{ policyNotice }}</p>

        <footer class="footer">
            <button class="link" type="button" @click="app.openWeb()">Open SprintSync</button>
            <button class="link" type="button" @click="settingsOpen = !settingsOpen">Settings</button>
        </footer>

        <div v-if="settingsOpen" class="card settings">
            <p class="small"><strong>{{ state.user?.name }}</strong><br /><span class="muted">{{ state.user?.email }}</span></p>
            <label v-if="state.preferences.launchAtLoginSupported" class="check">
                <input type="checkbox" :checked="state.preferences.launchAtLogin" @change="toggleLaunchAtLogin" />
                Launch SprintSync when I sign in
            </label>
            <button class="btn" type="button" :disabled="state.busy" data-testid="sign-out" @click="app.signOut()">Sign out</button>
            <p class="muted small about" data-testid="app-version">
                <strong>SprintSync</strong><br />
                Version {{ state.app.version }}<template v-if="state.app.channel === 'uat'"><br />UAT build</template>
            </p>
        </div>
    </section>
</template>

<style scoped>
.panel {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.banner {
    margin: 0;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--muted);
    font-size: 12px;
    border: 0;
    text-align: left;
}

.banner.warning {
    background: hsl(38 92% 50% / 0.14);
    color: var(--warning);
    font-weight: 600;
}

.notice {
    cursor: pointer;
}

.notice.error {
    background: hsl(0 72% 51% / 0.12);
    color: var(--danger);
}

.timer {
    display: grid;
    gap: 10px;
    text-align: center;
}

.timer.running {
    border-color: hsl(78 100% 40% / 0.6);
}

.what p {
    margin: 0;
}

.project {
    font-weight: 700;
    font-size: 15px;
}

.task {
    font-size: 13px;
}

.clock {
    margin: 6px 0;
    font-size: 44px;
    font-weight: 700;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}

.status {
    margin: -6px 0 0;
    color: var(--warning);
    font-weight: 600;
}

.actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.ready {
    display: grid;
    gap: 12px;
}

.today {
    display: grid;
    gap: 4px;
    padding: 0 4px;
    font-variant-numeric: tabular-nums;
}

.today .row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}

.actions.three {
    grid-template-columns: 1fr 1fr 1fr;
}

.clock.over {
    color: var(--warning);
}

.review {
    justify-self: center;
}

.idle {
    display: grid;
    gap: 14px;
}

.idle-row {
    display: grid;
    gap: 6px;
}

.idle-row p {
    margin: 0;
}

.keep {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 6px;
}

.keep input {
    height: 36px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--background);
    padding: 0 10px;
    user-select: text;
}

.notice-policy {
    margin: 0;
    padding: 0 4px;
}

.today strong {
    font-size: 18px;
}

.footer {
    margin-top: auto;
    display: flex;
    justify-content: space-between;
}

.link {
    background: none;
    border: 0;
    color: var(--muted-foreground);
    font-size: 12px;
    cursor: pointer;
    padding: 4px;
}

.settings {
    display: grid;
    gap: 12px;
}

.settings p {
    margin: 0;
}

.check {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 13px;
}
</style>
