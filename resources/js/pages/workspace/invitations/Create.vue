<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Copy, CreditCard, Handshake, Key, Link2, LoaderCircle, Mail, RefreshCw, Send, Trash2, User } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        workspace: { name: string; slug: string };
        seats?: { used: number; total: number };
        inviteLink: { url: string; expires_at: string; uses: number } | null;
        canManageInviteLink: boolean;
        workspaceRoles?: { id: number; name: string; permissions: string[] }[];
    }>(),
    {
        workspaceRoles: () => [],
    },
);

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm<{ email: string; role: string; workspace_role_id: number | null }>({
    email: '',
    role: 'member',
    workspace_role_id: null,
});

const selectedCustomRole = computed(() => props.workspaceRoles.find((role) => role.id === form.workspace_role_id) ?? null);

const submit = () => {
    form.post(workspaceRoute('workspace.invitations.store', { workspace: props.workspace.slug }), {
        onSuccess: () => form.reset(),
    });
};

const notifications = useNotificationStore();
const { copy, isSupported: canCopyToClipboard } = useClipboard();

const linkProcessing = ref(false);

const linkExpiryLabel = computed(() =>
    props.inviteLink === null
        ? ''
        : new Date(props.inviteLink.expires_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }),
);

function generateInviteLink() {
    if (linkProcessing.value) return;

    linkProcessing.value = true;

    router.post(
        workspaceRoute('workspace.invite-link.store', { workspace: props.workspace.slug }),
        {},
        {
            preserveScroll: true,
            onError: () => notifications.error('Could not generate an invite link.'),
            onFinish: () => (linkProcessing.value = false),
        },
    );
}

function revokeInviteLink() {
    if (linkProcessing.value) return;

    linkProcessing.value = true;

    router.delete(workspaceRoute('workspace.invite-link.destroy', { workspace: props.workspace.slug }), {
        preserveScroll: true,
        onError: () => notifications.error('Could not revoke the invite link.'),
        onFinish: () => (linkProcessing.value = false),
    });
}

async function copyInviteLink() {
    if (props.inviteLink === null) return;

    if (!canCopyToClipboard.value) {
        notifications.error('Your browser blocked clipboard access.');

        return;
    }

    await copy(props.inviteLink.url);
    notifications.success('Invite link copied.');
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Team', href: workspaceRoute('workspace.teams.index', { workspace: props.workspace.slug }) },
    { title: 'Invite member', href: workspaceRoute('workspace.invitations.create', { workspace: props.workspace.slug }) },
];

const roleOptions = [
    {
        value: 'member',
        label: 'Member',
        description: 'Can create and complete tasks, comment, and view sprints.',
        icon: User,
        badge: 'Default',
    },
    {
        value: 'admin',
        label: 'Admin',
        description: 'Everything members can do, plus manage projects, sprints, and integrations.',
        icon: Key,
    },
    {
        value: 'client',
        label: 'Client',
        description: 'External guest. Sees only the projects you add them to, with the access their client role allows.',
        icon: Handshake,
    },
];
</script>

<template>
    <Head title="Invite member" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-xl flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace · Team"
                title="Invite a teammate"
                description="They'll get an email to join your workspace on SprintSync."
            />

            <form @submit.prevent="submit" class="bg-card flex flex-col rounded-xl border shadow-sm">
                <div class="flex flex-col gap-5 p-5 sm:p-6">
                    <AppFormInput
                        v-model="form.email"
                        id="email"
                        label="Email address"
                        type="email"
                        required
                        autofocus
                        autocomplete="email"
                        placeholder="rajab@example.com"
                        hint="They'll receive a one-click invite — no password required from you."
                        :icon="Mail"
                    />

                    <div class="grid gap-2">
                        <Label class="text-[13px] font-medium">Role</Label>

                        <AppRadioCard v-model="form.role" :options="roleOptions" accent="blue" />

                        <InputError :message="form.errors.role" />
                    </div>

                    <div v-if="workspaceRoles.length > 0" class="grid gap-2">
                        <Label class="text-[13px] font-medium">Custom role <span class="text-muted-foreground font-normal">(optional)</span></Label>

                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                                :class="form.workspace_role_id === null ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                                @click="form.workspace_role_id = null"
                            >
                                <Check v-if="form.workspace_role_id === null" class="size-3" />
                                None
                            </button>

                            <button
                                v-for="workspaceRole in workspaceRoles"
                                :key="workspaceRole.id"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                                :class="
                                    form.workspace_role_id === workspaceRole.id
                                        ? 'border-primary ring-primary/20 ring-2'
                                        : 'hover:border-foreground/20'
                                "
                                @click="form.workspace_role_id = workspaceRole.id"
                            >
                                <Check v-if="form.workspace_role_id === workspaceRole.id" class="size-3" />
                                {{ workspaceRole.name }}
                            </button>
                        </div>

                        <p v-if="selectedCustomRole" class="text-muted-foreground text-[11px] leading-relaxed">
                            <template v-if="selectedCustomRole.permissions.length > 0">
                                Grants: {{ selectedCustomRole.permissions.join(', ') }}
                            </template>
                            <template v-else> This role grants no extra permissions yet. </template>
                        </p>
                        <p v-else class="text-muted-foreground text-[11px] leading-relaxed">
                            Custom roles add permissions on top of the role above. They're managed in workspace settings.
                        </p>

                        <InputError :message="form.errors.workspace_role_id" />
                    </div>
                </div>

                <div
                    class="bg-muted/20 flex flex-col-reverse items-stretch gap-3 border-t px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6"
                >
                    <div v-if="seats" class="text-muted-foreground flex items-center gap-1.5 text-[11.5px]">
                        <CreditCard class="size-3.5" />
                        <span class="tabular-nums"> {{ seats.used }} of {{ seats.total }} seats used </span>
                    </div>
                    <div v-else />

                    <div class="flex items-center justify-end gap-2">
                        <Button as-child variant="ghost" size="sm" type="button" tabindex="3">
                            <Link :href="workspaceRoute('workspace.teams.index', { workspace: props.workspace.slug })"> Cancel </Link>
                        </Button>

                        <Button type="submit" size="sm" tabindex="2" :disabled="form.processing" class="gap-1.5">
                            <LoaderCircle v-if="form.processing" class="size-3.5 animate-spin" />
                            <Send v-else class="size-3.5" />
                            Send invitation
                        </Button>
                    </div>
                </div>
            </form>
            <div v-if="canManageInviteLink" class="bg-card flex flex-col rounded-xl border shadow-sm">
                <div class="flex flex-col gap-4 p-5 sm:p-6">
                    <div class="flex items-start gap-3">
                        <div class="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg">
                            <Link2 class="size-4" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <h2 class="text-[15px] font-semibold tracking-tight">Shareable invite link</h2>
                            <p class="text-muted-foreground mt-0.5 text-xs leading-relaxed">
                                Anyone with this link can join as a Member until it expires. Revoke it if it leaks.
                            </p>
                        </div>
                    </div>

                    <template v-if="inviteLink">
                        <div class="border-input bg-muted flex items-center gap-2 overflow-hidden rounded-lg border px-3 py-2">
                            <span class="text-muted-foreground min-w-0 flex-1 truncate font-mono text-xs">{{ inviteLink.url }}</span>

                            <Button type="button" variant="ghost" size="sm" class="h-7 shrink-0 gap-1.5 text-xs" @click="copyInviteLink">
                                <Copy class="size-3.5" />
                                Copy
                            </Button>
                        </div>

                        <div class="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                            <span class="inline-flex items-center gap-1.5">
                                <Check class="size-3.5 text-emerald-500" />
                                Expires {{ linkExpiryLabel }}
                            </span>
                            <span class="tabular-nums">Used {{ inviteLink.uses }} {{ inviteLink.uses === 1 ? 'time' : 'times' }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button type="button" variant="outline" size="sm" class="gap-1.5" :disabled="linkProcessing" @click="generateInviteLink">
                                <RefreshCw class="size-3.5" />
                                Regenerate
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground hover:text-destructive gap-1.5"
                                :disabled="linkProcessing"
                                @click="revokeInviteLink"
                            >
                                <Trash2 class="size-3.5" />
                                Revoke
                            </Button>
                        </div>
                    </template>

                    <div v-else>
                        <Button type="button" size="sm" class="gap-1.5" :disabled="linkProcessing" @click="generateInviteLink">
                            <Link2 class="size-3.5" />
                            Generate invite link
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
