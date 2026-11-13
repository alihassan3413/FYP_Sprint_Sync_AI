<script setup lang="ts">
import { Check, Copy, Eye, Link2Off, Plus, RefreshCw, UserCheck, X } from 'lucide-vue-next';

export interface BoardSharing {
    access: 'view' | 'add' | null;
    url: string | null;
    requests: { id: number; name: string; email: string; requested_at: string }[];
}

const props = defineProps<{
    open: boolean;
    projectId: number;
    projectName: string;
    sharing: BoardSharing;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const { workspaceRoute } = useCurrentWorkspace();
const notify = useNotificationStore();

const options = [
    { value: null, label: 'Off', hint: 'Only project members can open the board.', icon: Link2Off },
    { value: 'view', label: 'Can view', hint: 'Anyone with the link sees the board. They can ask you to let them add tasks.', icon: Eye },
    {
        value: 'add',
        label: 'Can view and add tasks',
        hint: 'Anyone with the link sees the board and, once signed in, adds tasks straight away.',
        icon: Plus,
    },
] as const;

const saving = ref(false);
const deciding = ref<number | null>(null);
const copied = ref(false);

function save(access: BoardSharing['access'], regenerate = false) {
    router.put(
        workspaceRoute('workspace.projects.sharing.update', { project: props.projectId }),
        { access, regenerate },
        { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) },
    );
}

function decide(requestId: number, decision: 'approve' | 'decline') {
    router.post(
        workspaceRoute('workspace.projects.sharing.requests.decide', { project: props.projectId, accessRequest: requestId }),
        { decision },
        { preserveScroll: true, onStart: () => (deciding.value = requestId), onFinish: () => (deciding.value = null) },
    );
}

async function copyLink() {
    if (!props.sharing.url) return;

    try {
        await navigator.clipboard.writeText(props.sharing.url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        notify.error('Couldn’t copy the link. Select it and copy it instead.');
    }
}
</script>

<template>
    <AppModal
        :open="open"
        title="Share board"
        :description="`Let people outside the team see ${projectName} and add tasks.`"
        size="md"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <div class="grid gap-5 pt-1">
            <fieldset class="grid gap-2" :disabled="saving">
                <legend class="mb-2 text-sm font-medium">Anyone with the link</legend>
                <button
                    v-for="option in options"
                    :key="String(option.value)"
                    type="button"
                    class="flex items-start gap-3 rounded-xl border p-3 text-left transition-colors disabled:opacity-60"
                    :class="sharing.access === option.value ? 'border-primary bg-primary/5 ring-primary/20 ring-1' : 'hover:bg-muted/50'"
                    :aria-pressed="sharing.access === option.value"
                    @click="sharing.access !== option.value && save(option.value)"
                >
                    <span class="bg-muted flex size-8 shrink-0 items-center justify-center rounded-lg">
                        <component :is="option.icon" class="size-4" />
                    </span>
                    <span class="grid gap-0.5">
                        <span class="text-sm font-medium">{{ option.label }}</span>
                        <span class="text-muted-foreground text-xs">{{ option.hint }}</span>
                    </span>
                    <Check v-if="sharing.access === option.value" class="text-primary-text ml-auto size-4 shrink-0" />
                </button>
            </fieldset>

            <div v-if="sharing.access && sharing.url" class="grid gap-2">
                <label for="share-link" class="text-sm font-medium">Link</label>
                <div class="flex gap-2">
                    <input
                        id="share-link"
                        :value="sharing.url"
                        readonly
                        class="border-input bg-muted/40 min-w-0 flex-1 rounded-lg border px-3 py-2 text-xs"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                    <Button type="button" size="sm" class="h-9 shrink-0 gap-1.5" @click="copyLink">
                        <Check v-if="copied" class="size-3.5" />
                        <Copy v-else class="size-3.5" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 justify-self-start text-xs"
                    :disabled="saving"
                    @click="save(sharing.access, true)"
                >
                    <RefreshCw class="size-3" />
                    Create a new link (the old one stops working)
                </button>
            </div>

            <div class="grid gap-2">
                <p class="text-sm font-medium">Asking to add tasks</p>
                <ul v-if="sharing.requests.length" class="divide-y rounded-xl border">
                    <li v-for="request in sharing.requests" :key="request.id" class="flex items-center gap-3 px-3 py-2.5">
                        <AppAvatar :name="request.name" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ request.name }}</p>
                            <p class="text-muted-foreground truncate text-xs">{{ request.email }}</p>
                        </div>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-8 gap-1"
                            :disabled="deciding === request.id"
                            :aria-label="`Decline ${request.name}`"
                            @click="decide(request.id, 'decline')"
                        >
                            <X class="size-3.5" />
                        </Button>
                        <Button size="sm" class="h-8 gap-1.5" :disabled="deciding === request.id" @click="decide(request.id, 'approve')">
                            <UserCheck class="size-3.5" />
                            Approve
                        </Button>
                    </li>
                </ul>
                <p v-else class="text-muted-foreground rounded-xl border border-dashed px-3 py-4 text-center text-xs">No one is waiting.</p>
            </div>
        </div>
    </AppModal>
</template>
