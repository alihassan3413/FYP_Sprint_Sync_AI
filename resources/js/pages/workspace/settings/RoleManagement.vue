<script setup lang="ts">
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    Brush,
    ChevronDown,
    ChevronUp,
    Code,
    CreditCard,
    Crown,
    Eye,
    LayoutIcon,
    Plug,
    Plus,
    Shield,
    ShieldCheck,
    Trash,
    User,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// ─── Types ────────────────────────────────────────────────────────────────────

interface WorkspaceRoleData {
    id: number | null;
    name: string;
    slug: string;
    permissions: Record<string, boolean> | null;
    workspace_id: number;
    member_count?: number;
    is_system?: boolean;
}

interface PermissionGroup {
    key: string;
    label: string;
    icon: any;
    permissions: { key: string; label: string; hint: string }[];
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    roles?: WorkspaceRoleData[];
    workspaceId?: number;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const isCreateWorkspaceRoleModalOpen = ref(false);
// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: workspaceRoute('dashboard') },
    { title: 'Settings', href: workspaceRoute('workspace.settings') },
    { title: 'Role Management', href: '' },
];

// ─── Permission groups definition ─────────────────────────────────────────────

const permissionGroups: PermissionGroup[] = [
    {
        key: 'projects',
        label: 'Projects',
        icon: LayoutIcon,
        permissions: [
            { key: 'projects.view', label: 'View projects', hint: 'See all workspace projects' },
            { key: 'projects.create', label: 'Create projects', hint: 'Start new projects from scratch' },
            { key: 'projects.delete', label: 'Delete projects', hint: 'Permanently remove projects' },
        ],
    },
    {
        key: 'members',
        label: 'Members',
        icon: Users,
        permissions: [
            { key: 'members.invite', label: 'Invite members', hint: 'Send invitations to new teammates' },
            { key: 'members.remove', label: 'Remove members', hint: 'Kick members from workspace' },
            { key: 'members.roles', label: 'Manage roles', hint: 'Assign or change member roles' },
        ],
    },
    {
        key: 'billing',
        label: 'Billing',
        icon: CreditCard,
        permissions: [
            { key: 'billing.view', label: 'View invoices', hint: 'Read billing history' },
            { key: 'billing.manage', label: 'Manage billing', hint: 'Update payment methods and plan' },
        ],
    },
    {
        key: 'integrations',
        label: 'Integrations',
        icon: Plug,
        permissions: [
            { key: 'integrations.view', label: 'View integrations', hint: 'See connected apps' },
            { key: 'integrations.manage', label: 'Manage integrations', hint: 'Connect or disconnect services' },
            { key: 'integrations.deploy', label: 'Deploy & webhooks', hint: 'Trigger deployments and webhooks' },
        ],
    },
];

const allPermissionKeys = permissionGroups.flatMap((g) => g.permissions.map((p) => p.key));

// ─── System roles (static, non-editable) ─────────────────────────────────────

const systemRoles = [
    { slug: 'owner', name: 'Owner', desc: 'Full access. Cannot be removed.', icon: Crown, iconColor: '#534AB7', iconBg: '#EEEDFE', memberCount: 1 },
    {
        slug: 'admin',
        name: 'Admin',
        desc: 'Manage members, billing & settings.',
        icon: ShieldCheck,
        iconColor: '#3B6D11',
        iconBg: '#EAF3DE',
        memberCount: 3,
    },
    {
        slug: 'member',
        name: 'Member',
        desc: 'Default role for workspace members.',
        icon: User,
        iconColor: '#185FA5',
        iconBg: '#E6F1FB',
        memberCount: 12,
    },
    { slug: 'viewer', name: 'Viewer', desc: 'Read-only access to projects.', icon: Eye, iconColor: '#854F0B', iconBg: '#FAEEDA', memberCount: 5 },
];

const customIconMap: Record<string, any> = {
    developer: Code,
    designer: Brush,
};
const customIconBgMap: Record<string, string> = {
    developer: '#EEEDFE',
    designer: '#FBEAF0',
};
const customIconColorMap: Record<string, string> = {
    developer: '#534AB7',
    designer: '#993556',
};

// ─── State ────────────────────────────────────────────────────────────────────

const customRoles = ref<WorkspaceRoleData[]>(
    props.roles ?? [
        {
            id: 1,
            name: 'Developer',
            slug: 'developer',
            workspace_id: 1,
            member_count: 4,
            permissions: {
                'projects.view': true,
                'projects.create': true,
                'integrations.view': true,
                'integrations.manage': true,
                'integrations.deploy': true,
                'members.invite': true,
            },
        },
        {
            id: 2,
            name: 'Designer',
            slug: 'designer',
            workspace_id: 1,
            member_count: 2,
            permissions: { 'projects.view': true, 'projects.create': true },
        },
    ],
);

const selectedRoleId = ref<number | null>(customRoles.value[0]?.id ?? null);
const collapsedGroups = ref<Record<string, boolean>>({});

// Edit form (mirrors selected role)
const editName = ref('');
const editSlug = ref('');
const editPermissions = ref<Record<string, boolean>>({});

// ─── Computed ─────────────────────────────────────────────────────────────────

const selectedRole = computed(() => customRoles.value.find((r) => r.id === selectedRoleId.value) ?? null);

const enabledCount = computed(() => Object.values(editPermissions.value).filter(Boolean).length);
const totalCount = computed(() => allPermissionKeys.length);

// ─── Methods ──────────────────────────────────────────────────────────────────

function selectRole(role: WorkspaceRoleData) {
    selectedRoleId.value = role.id;
    editName.value = role.name;
    editSlug.value = role.slug;
    editPermissions.value = { ...role.permissions };
}

function toSlug(v: string) {
    return v
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '');
}

function onEditNameInput(v: string) {
    editSlug.value = toSlug(v);
}

function toggleGroup(key: string) {
    collapsedGroups.value[key] = !collapsedGroups.value[key];
}

function toggleAll() {
    const anyOff = allPermissionKeys.some((k) => !editPermissions.value[k]);
    const next: Record<string, boolean> = {};
    allPermissionKeys.forEach((k) => (next[k] = anyOff));
    editPermissions.value = next;
}

function saveRole() {
    if (!selectedRole.value) return;
    router.put(
        workspaceRoute('workspace.roles.update', selectedRole.value.id),
        {
            name: editName.value,
            slug: editSlug.value,
            permissions: editPermissions.value,
        },
        { preserveScroll: true },
    );
}

function deleteRole() {
    if (!selectedRole.value) return;
    router.delete(workspaceRoute('workspace.roles.destroy', selectedRole.value.id), { preserveScroll: true });
}

function openCreateWorkspaceRoleModal() {
    isCreateWorkspaceRoleModalOpen.value = true;
}

// Select first role on mount
if (customRoles.value.length) selectRole(customRoles.value[0]);
</script>

<template>
    <Head title="Roles Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <!-- Page Header -->
            <AppPageHeader
                eyebrow="Workspace"
                title="Role Management"
                description="Create custom roles and fine-tune what each member can do in your workspace."
            >
                <template #actions>
                    <Button size="sm" class="gap-1.5" @click="openCreateWorkspaceRoleModal">
                        <Plus class="size-3.5" />
                        New role
                    </Button>
                </template>
            </AppPageHeader>

            <!-- Main layout: role list + permissions panel -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_380px] lg:items-start">
                <!-- ── Left: role cards ── -->
                <div class="flex flex-col gap-5">
                    <!-- System roles -->
                    <div>
                        <p class="text-muted-foreground mb-2.5 text-[11px] font-medium tracking-[.06em] uppercase">System roles</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div
                                v-for="sr in systemRoles"
                                :key="sr.slug"
                                class="bg-card rounded-xl border p-4 opacity-80 shadow-sm transition-colors"
                            >
                                <div class="mb-2.5 flex size-9 items-center justify-center rounded-lg" :style="{ background: sr.iconBg }">
                                    <component :is="sr.icon" class="size-4" :style="{ color: sr.iconColor }" />
                                </div>
                                <p class="text-sm font-medium">{{ sr.name }}</p>
                                <p class="text-muted-foreground mt-0.5 text-xs leading-relaxed">{{ sr.desc }}</p>
                                <div class="mt-3 flex items-center justify-between">
                                    <span class="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase">
                                        System
                                    </span>
                                    <span class="text-muted-foreground flex items-center gap-1 text-[11px]">
                                        <Users class="size-3" />
                                        {{ sr.memberCount }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div class="flex items-center gap-3">
                        <div class="border-border h-px flex-1 border-t" />
                        <span class="text-muted-foreground text-[11px] tracking-[.04em] uppercase">Custom roles</span>
                        <div class="border-border h-px flex-1 border-t" />
                    </div>

                    <!-- Custom roles -->
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <button
                            v-for="role in customRoles"
                            :key="role.id"
                            class="bg-card rounded-xl border p-4 text-left shadow-sm transition-all"
                            :class="selectedRoleId === role.id ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                            @click="selectRole(role)"
                        >
                            <div
                                class="mb-2.5 flex size-9 items-center justify-center rounded-lg"
                                :style="{ background: customIconBgMap[role.slug] ?? '#EEEDFE' }"
                            >
                                <component
                                    :is="customIconMap[role.slug] ?? Code"
                                    class="size-4"
                                    :style="{ color: customIconColorMap[role.slug] ?? '#534AB7' }"
                                />
                            </div>
                            <p class="text-sm font-medium">{{ role.name }}</p>
                            <p class="text-muted-foreground mt-0.5 line-clamp-2 text-xs leading-relaxed">
                                {{ Object.values(role.permissions ?? {}).filter(Boolean).length }} permissions enabled
                            </p>
                            <div class="mt-3 flex items-center justify-between">
                                <span
                                    class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-emerald-800 uppercase dark:bg-emerald-950 dark:text-emerald-300"
                                >
                                    Custom
                                </span>
                                <span class="text-muted-foreground flex items-center gap-1 text-[11px]">
                                    <Users class="size-3" />
                                    {{ role.member_count ?? 0 }}
                                </span>
                            </div>
                        </button>

                        <!-- Add new -->
                        <button
                            class="border-border text-muted-foreground hover:bg-muted flex min-h-[116px] flex-col items-center justify-center gap-2 rounded-xl border border-dashed transition-colors"
                            @click="openCreateWorkspaceRoleModal"
                        >
                            <Plus class="size-5" />
                            <span class="text-sm font-medium">New role</span>
                        </button>
                    </div>
                </div>

                <!-- ── Right: permissions panel ── -->
                <div class="bg-card overflow-hidden rounded-xl border shadow-sm">
                    <template v-if="selectedRole">
                        <!-- Role name + slug form -->
                        <div class="border-b p-4">
                            <div class="mb-3">
                                <label class="text-muted-foreground mb-1.5 block text-xs font-medium">Role name</label>
                                <input
                                    v-model="editName"
                                    type="text"
                                    class="border-input bg-background focus:ring-primary/30 w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2"
                                    @input="onEditNameInput(editName)"
                                />
                            </div>
                            <div>
                                <label class="text-muted-foreground mb-1.5 block text-xs font-medium">Identifier</label>
                                <div class="border-input bg-muted flex overflow-hidden rounded-lg border text-sm">
                                    <span class="text-muted-foreground border-input border-r px-3 py-2 text-xs">workspace.</span>
                                    <input v-model="editSlug" type="text" class="flex-1 bg-transparent px-3 py-2 text-sm outline-none" />
                                </div>
                            </div>
                        </div>

                        <!-- Permissions header -->
                        <div class="flex items-center justify-between border-b px-4 py-3">
                            <div class="flex items-center gap-2 text-sm font-medium">
                                <Shield class="text-primary size-3.5" />
                                Permissions
                                <span class="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium">
                                    {{ enabledCount }} / {{ totalCount }}
                                </span>
                            </div>
                            <button class="text-muted-foreground hover:text-foreground text-xs transition-colors" @click="toggleAll">
                                Toggle all
                            </button>
                        </div>

                        <!-- Permission groups -->
                        <div>
                            <div v-for="group in permissionGroups" :key="group.key" class="border-b last:border-b-0">
                                <!-- Group header -->
                                <button class="bg-muted/50 flex w-full items-center justify-between px-4 py-2.5" @click="toggleGroup(group.key)">
                                    <div class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[.04em] uppercase">
                                        <component :is="group.icon" class="size-3.5" />
                                        {{ group.label }}
                                    </div>
                                    <component :is="collapsedGroups[group.key] ? ChevronDown : ChevronUp" class="text-muted-foreground size-3.5" />
                                </button>

                                <!-- Permission rows -->
                                <div v-if="!collapsedGroups[group.key]">
                                    <div
                                        v-for="perm in group.permissions"
                                        :key="perm.key"
                                        class="hover:bg-muted/30 flex items-center justify-between border-t px-4 py-2.5 transition-colors"
                                    >
                                        <div>
                                            <p class="text-sm">{{ perm.label }}</p>
                                            <p class="text-muted-foreground text-[11px]">{{ perm.hint }}</p>
                                        </div>
                                        <!-- Toggle switch -->
                                        <label class="relative inline-flex cursor-pointer items-center">
                                            <input v-model="editPermissions[perm.key]" type="checkbox" class="peer sr-only" />
                                            <div
                                                class="peer-checked:bg-primary bg-border relative h-5 w-9 rounded-full transition-colors after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"
                                            />
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer actions -->
                        <div class="bg-muted/30 flex items-center justify-between border-t px-4 py-3">
                            <button
                                class="border-destructive text-destructive hover:bg-destructive/10 flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                                @click="deleteRole"
                            >
                                <Trash class="size-3.5" />
                                Delete role
                            </button>
                            <Button size="sm" @click="saveRole">Save changes</Button>
                        </div>
                    </template>

                    <!-- Empty state -->
                    <div v-else class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                        <Shield class="text-muted-foreground size-8 opacity-40" />
                        <p class="text-muted-foreground text-sm">Select a custom role to configure its permissions.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <CreateWorkspaceRoleModal v-model:open="isCreateWorkspaceRoleModalOpen" />
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.15s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-active .bg-card,
.modal-leave-active .bg-card {
    transition: transform 0.15s ease;
}
.modal-enter-from .bg-card,
.modal-leave-to .bg-card {
    transform: scale(0.97);
}
</style>
