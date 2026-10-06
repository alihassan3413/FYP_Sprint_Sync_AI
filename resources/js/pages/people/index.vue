<script lang="ts">
/*
 * Browser Back restores this page with the props it had at the time, which can
 * predate access that was just given or a team member who was just added.
 */
let restoredFromHistory = false;

if (typeof window !== 'undefined') {
    window.addEventListener('popstate', () => (restoredFromHistory = true));
}
</script>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Plus, Send, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type Member, type WorkspaceRoleOption } from '@/lib/members';
import type { Department, LinkableUser } from '@/lib/people';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    members: Member[];
    /** Job titles, departments and team members without a login are included. */
    canViewProfiles: boolean;
    canManagePeople: boolean;
    canManageMembers: boolean;
    canInviteMembers: boolean;
    workspaceRoles: WorkspaceRoleOption[];
    departments?: Department[];
    linkableUsers?: LinkableUser[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const notifications = useNotificationStore();
const { copy, isSupported: canCopyToClipboard } = useClipboard();

useDockContext('teams');

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Team', href: workspaceRoute('workspace.people.index') }];

const search = ref('');
const filter = ref<string>('all');

const filterOptions = computed(() => [
    { value: 'all', label: 'All', count: props.members.length },
    { value: 'active', label: 'Has access', count: props.members.filter((m) => m.status === 'active').length },
    { value: 'pending', label: 'Invited', count: props.members.filter((m) => m.status === 'pending').length },
    ...(props.canViewProfiles ? [{ value: 'none', label: 'No access', count: props.members.filter((m) => m.status === 'none').length }] : []),
]);

const visible = computed(() => {
    const query = search.value.trim().toLowerCase();

    return props.members
        .filter((m) => {
            if (filter.value !== 'all' && m.status !== filter.value) return false;
            if (!query) return true;

            return [m.name, m.email, m.role, m.workspace_role_name, m.person?.title, m.person?.department].some((value) =>
                value?.toLowerCase().includes(query),
            );
        })
        .sort((a, b) => a.name.localeCompare(b.name));
});

/** Job details when the viewer may see them, otherwise the email. */
function subtitle(m: Member): string {
    if (m.person) {
        return [m.person.title, m.person.department].filter(Boolean).join(' · ') || 'No job details yet';
    }

    return m.status === 'pending' ? 'Invited by email' : m.email;
}

function canAddDetails(m: Member): boolean {
    return props.canManagePeople && !m.person && m.status === 'active' && m.role !== 'client';
}

/** A row opens the team member's page; a login without a profile opens "Add job details". */
function rowTarget(m: Member): { is: typeof Link | 'button' | 'div'; attrs: Record<string, unknown> } {
    if (m.person && props.canViewProfiles) {
        return { is: Link, attrs: { href: workspaceRoute('workspace.people.show', { person: m.person.public_id }) } };
    }

    if (canAddDetails(m)) {
        return { is: 'button', attrs: { type: 'button', onClick: () => addDetails(m), 'aria-label': `Add job details for ${m.name}` } };
    }

    return { is: 'div', attrs: {} };
}

/* ---- Team member profiles ---------------------------------------------- */

const isPersonModalOpen = ref(false);
const prefill = ref<{ name: string; email: string; user_id: number } | null>(null);

function addTeamMember() {
    prefill.value = null;
    isPersonModalOpen.value = true;
}

function addDetails(m: Member) {
    prefill.value = { name: m.name, email: m.email, user_id: Number(m.id) };
    isPersonModalOpen.value = true;
}

/* ---- SprintSync access ------------------------------------------------- */

const accessTarget = ref<{ public_id: string; name: string; email: string | null } | null>(null);
const roleTarget = ref<Member | null>(null);
const removeTarget = ref<Member | null>(null);
const busy = ref(false);

function run(method: 'post' | 'delete', url: string, data: Record<string, string>, failure: string) {
    if (busy.value) return;

    busy.value = true;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        onError: () => notifications.error(failure),
        onFinish: () => (busy.value = false),
    });
}

function giveAccess(m: Member) {
    if (m.person) {
        accessTarget.value = { public_id: m.person.public_id, name: m.name, email: m.email || null };
    }
}

function connect(m: Member) {
    if (m.person) {
        run(
            'post',
            workspaceRoute('workspace.people.access.store', { person: m.person.public_id }),
            { email: m.email },
            `Could not connect ${m.name}.`,
        );
    }
}

function resendInvite(m: Member) {
    if (m.invitation_id) {
        run(
            'post',
            workspaceRoute('workspace.invitations.resend', { invitation: m.invitation_id }),
            {},
            `Could not resend the invitation to ${m.email}.`,
        );
    }
}

function cancelInvite(m: Member) {
    if (m.invitation_id) {
        run(
            'delete',
            workspaceRoute('workspace.invitations.destroy', { invitation: m.invitation_id }),
            {},
            `Could not cancel the invitation to ${m.email}.`,
        );
    }
}

async function copyInviteLink(m: Member) {
    if (!m.invite_url) return;

    if (!canCopyToClipboard.value) {
        notifications.error('Your browser blocked clipboard access.');

        return;
    }

    await copy(m.invite_url);
    notifications.success('Invite link copied.');
}

onMounted(() => {
    if (restoredFromHistory) {
        restoredFromHistory = false;
        router.reload({ only: ['members'] });
    }
});
</script>

<template>
    <Head title="Team" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace"
                title="Team"
                :description="
                    canViewProfiles
                        ? 'Everyone who works with your business. SprintSync access is separate for each person.'
                        : 'Everyone who can sign in to this workspace.'
                "
            >
                <template #actions>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="canInviteMembers" :variant="canManagePeople ? 'outline' : 'default'" as-child size="sm" class="gap-1.5">
                            <Link :href="workspaceRoute('workspace.invitations.create')">
                                <Send class="size-3.5" />
                                {{ canManagePeople ? 'Invite link' : 'Invite' }}
                            </Link>
                        </Button>
                        <Button v-if="canManagePeople" size="sm" class="gap-1.5" @click="addTeamMember">
                            <Plus class="size-3.5" />
                            Add team member
                        </Button>
                    </div>
                </template>
            </AppPageHeader>

            <div class="bg-card border-border/70 overflow-hidden rounded-3xl border">
                <AppListToolBar v-model:search="search" v-model:filter="filter" :filter-options="filterOptions" search-placeholder="Search team…" />

                <ul v-if="visible.length" class="divide-y border-t" data-testid="team-list">
                    <li
                        v-for="m in visible"
                        :key="m.id"
                        class="group hover:bg-muted/40 flex items-center transition-colors"
                        :class="m.is_self && 'bg-violet-50/30 dark:bg-violet-500/5'"
                        :data-testid="`team-row-${m.person?.public_id ?? m.id}`"
                    >
                        <component
                            :is="rowTarget(m).is"
                            v-bind="rowTarget(m).attrs"
                            class="focus-visible:ring-ring flex min-w-0 flex-1 items-center gap-4 py-3.5 pl-4 text-left outline-none focus-visible:ring-2 focus-visible:ring-inset sm:pl-5"
                            :class="rowTarget(m).is !== 'div' && 'cursor-pointer'"
                        >
                            <AppAvatar :name="m.name" :email="m.email" :src="m.avatar_url" size="lg" />
                            <div class="min-w-0 flex-1">
                                <p class="text-foreground truncate font-medium">
                                    {{ m.name }}
                                    <span v-if="m.is_self" class="text-muted-foreground text-xs font-normal">(you)</span>
                                </p>
                                <p class="text-muted-foreground truncate text-sm">{{ subtitle(m) }}</p>
                                <TeamAccessBadge :member="m" class="mt-1.5 sm:hidden" />
                            </div>
                            <div class="hidden shrink-0 sm:block"><TeamAccessBadge :member="m" /></div>
                            <ChevronRight
                                v-if="rowTarget(m).is === Link"
                                class="text-muted-foreground/60 group-hover:text-foreground hidden size-4 shrink-0 transition-transform group-hover:translate-x-0.5 sm:block"
                            />
                        </component>

                        <div class="flex w-12 shrink-0 justify-center">
                            <MemberActionsMenu
                                :member="m"
                                :can-manage-members="canManageMembers"
                                :can-invite-members="canInviteMembers"
                                :can-manage-people="canManagePeople"
                                @resend-invite="resendInvite"
                                @copy-invite-link="copyInviteLink"
                                @revoke-invite="cancelInvite"
                                @change-role="(member: Member) => (roleTarget = member)"
                                @remove="(member: Member) => (removeTarget = member)"
                                @give-access="giveAccess"
                                @connect="connect"
                                @add-details="addDetails"
                            />
                        </div>
                    </li>
                </ul>

                <AppEmptyState
                    v-else
                    :title="search || filter !== 'all' ? 'Nobody matches your filters' : 'No team members yet'"
                    :description="
                        search || filter !== 'all'
                            ? 'Try adjusting your search or clearing filters.'
                            : 'Add the people who work with your business, with or without SprintSync access.'
                    "
                >
                    <template #icon>
                        <Users class="size-5" />
                    </template>
                    <template v-if="search || filter !== 'all'" #actions>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="
                                search = '';
                                filter = 'all';
                            "
                        >
                            Clear filters
                        </Button>
                    </template>
                </AppEmptyState>
            </div>
        </div>
    </AppLayout>

    <PersonFormModal
        v-if="canManagePeople"
        v-model:open="isPersonModalOpen"
        :departments="departments ?? []"
        :linkable-users="linkableUsers ?? []"
        :prefill="prefill"
    />

    <GiveAccessModal
        :open="accessTarget !== null"
        :person="accessTarget"
        :workspace-roles="workspaceRoles"
        @update:open="(value) => !value && (accessTarget = null)"
    />

    <ChangeMemberRoleModal
        :open="roleTarget !== null"
        :member="roleTarget"
        :workspace-roles="workspaceRoles"
        @update:open="(value) => !value && (roleTarget = null)"
    />

    <RemoveMemberDialog :open="removeTarget !== null" :member="removeTarget" @update:open="(value) => !value && (removeTarget = null)" />
</template>
