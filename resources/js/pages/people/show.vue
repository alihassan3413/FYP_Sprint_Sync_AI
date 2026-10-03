<script setup lang="ts">
import { Briefcase, Building2, CalendarDays, Link2, Mail, MailCheck, Pencil, Settings2, ShieldCheck, ShieldOff, UserPlus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type Member, type WorkspaceRoleOption } from '@/lib/members';
import type { Department, LinkableUser, Person } from '@/lib/people';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    person: Person;
    /** This team member's row in the Team list: their SprintSync access. */
    access: Member;
    departments: Department[];
    linkableUsers: LinkableUser[];
    canManagePeople: boolean;
    canManageMembers: boolean;
    canInviteMembers: boolean;
    workspaceRoles: WorkspaceRoleOption[];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Team', href: workspaceRoute('workspace.people.index') },
    { title: props.person.name, href: workspaceRoute('workspace.people.show', { person: props.person.public_id }) },
]);

const firstName = computed(() => props.person.name.split(' ')[0]);

const addedOn = computed(() => new Date(props.person.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));

/** Icon, colours and wording for the access card, one entry per state. */
const accessState = computed(() => {
    if (props.access.status === 'pending') {
        return {
            icon: MailCheck,
            tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
            title: 'Invitation pending',
            detail: `Sent to ${props.access.email}`,
        };
    }

    if (props.access.status === 'none') {
        return justAdded.value && props.canManagePeople && props.canInviteMembers
            ? {
                  icon: UserPlus,
                  tone: 'bg-primary/15 text-primary-text',
                  title: `${firstName.value} has been added`,
                  detail: `Does ${firstName.value} need SprintSync access?`,
              }
            : {
                  icon: ShieldOff,
                  tone: 'bg-muted text-muted-foreground',
                  title: 'No SprintSync access',
                  detail: `${firstName.value} doesn't need a login to be on your team.`,
              };
    }

    if (props.access.link_suggested) {
        return {
            icon: Link2,
            tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
            title: `${firstName.value} already has SprintSync access`,
            detail: `Signs in as ${props.access.email}`,
        };
    }

    return {
        icon: ShieldCheck,
        tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        title: 'Has access',
        detail: props.person.linked_user ? `Signs in as ${props.person.linked_user.email}` : 'Can sign in to SprintSync',
    };
});

const isEditModalOpen = ref(false);
const isGiveAccessOpen = ref(false);
const isManageAccessOpen = ref(false);
const isRoleModalOpen = ref(false);
const isRemoveOpen = ref(false);
const connecting = ref(false);

/** Set right after "Add team member", so the page can ask whether they need access. */
const justAdded = ref(typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('added'));

const isConnected = computed(() => props.access.status === 'active' && !props.access.link_suggested);
const canManageAccess = computed(
    () =>
        (isConnected.value && (props.canManagePeople || (props.canManageMembers && props.access.role !== 'owner'))) ||
        (props.access.status === 'pending' && props.canInviteMembers),
);

function connect() {
    if (connecting.value) return;

    connecting.value = true;
    router.post(
        workspaceRoute('workspace.people.access.store', { person: props.person.public_id }),
        { email: props.access.email },
        { preserveScroll: true, onFinish: () => (connecting.value = false) },
    );
}
</script>

<template>
    <Head :title="person.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <!-- Profile header -->
            <section class="bg-card border-border/70 rounded-3xl border">
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:gap-5 sm:p-6">
                    <AppAvatar :name="person.name" size="2xl" class="shrink-0" />
                    <div class="min-w-0 flex-1">
                        <h1 class="text-foreground truncate text-2xl font-semibold tracking-tight">{{ person.name }}</h1>
                        <p v-if="person.email" class="text-muted-foreground mt-0.5 truncate text-sm">{{ person.email }}</p>
                        <div class="text-muted-foreground mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span
                                v-if="person.title"
                                class="bg-muted text-foreground inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            >
                                <Briefcase class="size-3.5" />
                                {{ person.title }}
                            </span>
                            <span
                                v-if="person.department"
                                class="bg-muted text-foreground inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            >
                                <Building2 class="size-3.5" />
                                {{ person.department.name }}
                            </span>
                            <span v-if="!person.title && !person.department" class="text-xs">No job details yet</span>
                        </div>
                    </div>
                    <Button
                        v-if="canManagePeople"
                        variant="outline"
                        size="sm"
                        class="gap-1.5 self-start sm:self-center"
                        @click="isEditModalOpen = true"
                    >
                        <Pencil class="size-3.5" />
                        Edit profile
                    </Button>
                </div>
            </section>

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <!-- Details -->
                <section class="bg-card border-border/70 rounded-3xl border">
                    <h2 class="px-5 pt-5 text-sm font-semibold sm:px-6">Work details</h2>
                    <dl class="divide-border/70 mt-3 divide-y">
                        <div
                            v-for="item in [
                                { icon: Briefcase, label: 'Job title', value: person.title },
                                { icon: Building2, label: 'Department', value: person.department?.name },
                                { icon: Mail, label: 'Email', value: person.email },
                                { icon: CalendarDays, label: 'Added', value: addedOn },
                            ]"
                            :key="item.label"
                            class="flex items-center gap-4 px-5 py-3.5 sm:px-6"
                        >
                            <span class="bg-muted text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg">
                                <component :is="item.icon" class="size-4" />
                            </span>
                            <dt class="text-muted-foreground w-28 shrink-0 text-sm">{{ item.label }}</dt>
                            <dd class="min-w-0 flex-1 truncate text-sm font-medium" :class="!item.value && 'text-muted-foreground font-normal'">
                                {{ item.value || 'Not set' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- SprintSync access -->
                <section class="bg-card border-border/70 flex flex-col gap-5 rounded-3xl border p-5 sm:p-6" data-testid="access-section">
                    <h2 class="text-sm font-semibold">SprintSync access</h2>

                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="accessState.tone">
                            <component :is="accessState.icon" class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-medium">{{ accessState.title }}</p>
                            <p class="text-muted-foreground text-sm break-words">{{ accessState.detail }}</p>
                            <div v-if="isConnected" class="mt-2 flex flex-wrap items-center gap-2">
                                <AppRoleBadge :role="access.role ?? 'member'" />
                                <span v-if="access.workspace_role_name" class="text-muted-foreground text-xs">{{ access.workspace_role_name }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Button v-if="canManageAccess" variant="outline" class="w-full gap-1.5" @click="isManageAccessOpen = true">
                            <Settings2 class="size-4" />
                            Manage access
                        </Button>
                        <Button
                            v-else-if="access.status === 'active' && canManagePeople"
                            class="w-full gap-1.5"
                            :disabled="connecting"
                            @click="connect"
                        >
                            <Link2 class="size-4" />
                            Connect profile
                        </Button>
                        <template v-else-if="access.status === 'none' && canManagePeople && canInviteMembers">
                            <Button class="w-full gap-1.5" @click="isGiveAccessOpen = true">
                                <UserPlus class="size-4" />
                                Give access
                            </Button>
                            <Button v-if="justAdded" variant="ghost" class="w-full" @click="justAdded = false">Not now</Button>
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>

    <PersonFormModal
        v-if="canManagePeople"
        v-model:open="isEditModalOpen"
        :departments="departments"
        :linkable-users="linkableUsers"
        :person="person"
    />

    <GiveAccessModal
        v-model:open="isGiveAccessOpen"
        :person="{ public_id: person.public_id, name: person.name, email: person.email }"
        :workspace-roles="workspaceRoles"
    />

    <ManageAccessModal
        v-model:open="isManageAccessOpen"
        :access="access"
        :person-public-id="person.public_id"
        :login="person.linked_user"
        :can-manage-members="canManageMembers"
        :can-invite-members="canInviteMembers"
        :can-manage-people="canManagePeople"
        @change-role="isRoleModalOpen = true"
        @remove="isRemoveOpen = true"
    />

    <ChangeMemberRoleModal
        :open="isRoleModalOpen"
        :member="isRoleModalOpen ? access : null"
        :workspace-roles="workspaceRoles"
        @update:open="(value) => (isRoleModalOpen = value)"
    />

    <RemoveMemberDialog :open="isRemoveOpen" :member="isRemoveOpen ? access : null" @update:open="(value) => (isRemoveOpen = value)" />
</template>
