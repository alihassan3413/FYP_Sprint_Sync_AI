<script setup lang="ts">
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Brush,
    ChevronDown,
    ChevronUp,
    Code,
    CreditCard,
    Crown,
    Info,
    LayoutIcon,
    Loader2,
    Plug,
    Plus,
    Shield,
    ShieldCheck,
    Trash,
    User,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch, type Component } from 'vue';

interface WorkspaceRoleData {
    id: number;
    name: string;
    slug: string;
    permissions: Record<string, boolean> | null;
    workspace_id: number;
    member_count?: number | null;
}

interface SystemRole {
    value: string;
    label: string;
    description: string;
    member_count: number;
}

interface PermissionGroup {
    key: string;
    label: string;
    icon: Component;
    permissions: { key: string; label: string; hint: string }[];
}

const props = defineProps<{
    roles: WorkspaceRoleData[];
    systemRoles: SystemRole[];
    availablePermissions: string[];
    canManageRoles: boolean;
    workspaceId: number;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const isCreateWorkspaceRoleModalOpen = ref(false);
const isDeleteDialogOpen = ref(false);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: workspaceRoute('dashboard') },
    { title: 'Settings', href: workspaceRoute('workspace.settings') },
    { title: 'Role Management', href: '' },
];

const allPermissionGroups: PermissionGroup[] = [
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
            { key: 'members.remove', label: 'Remove members', hint: 'Remove members from the workspace' },
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

const permissionGroups = computed(() =>
    allPermissionGroups
        .map((group) => ({
            ...group,
            permissions: group.permissions.filter((permission) => props.availablePermissions.includes(permission.key)),
        }))
        .filter((group) => group.permissions.length > 0),
);

const allPermissionKeys = computed(() => permissionGroups.value.flatMap((group) => group.permissions.map((permission) => permission.key)));

const systemRoleStyles: Record<string, { icon: Component; iconColor: string; iconBg: string }> = {
    owner: { icon: Crown, iconColor: '#534AB7', iconBg: '#EEEDFE' },
    admin: { icon: ShieldCheck, iconColor: '#3B6D11', iconBg: '#EAF3DE' },
    member: { icon: User, iconColor: '#185FA5', iconBg: '#E6F1FB' },
};

const customIconMap: Record<string, Component> = {
    developer: Code,
    designer: Brush,
};

const roles = computed(() => props.roles ?? []);

const selectedRoleId = ref<number | null>(roles.value[0]?.id ?? null);
const collapsedGroups = ref<Record<string, boolean>>({});

const selectedRole = computed(() => roles.value.find((role) => role.id === selectedRoleId.value) ?? null);

const form = useForm<{ name: string; permissions: Record<string, boolean> }>({
    name: '',
    permissions: {},
});

function permissionsFor(role: WorkspaceRoleData): Record<string, boolean> {
    const next: Record<string, boolean> = {};

    allPermissionKeys.value.forEach((key) => {
        next[key] = role.permissions?.[key] === true;
    });

    return next;
}

function loadSelectedRole() {
    const role = selectedRole.value;

    if (role === null) {
        return;
    }

    form.defaults({ name: role.name, permissions: permissionsFor(role) });
    form.reset();
    form.clearErrors();
}

watch(selectedRoleId, loadSelectedRole, { immediate: true });

const pendingSlug = ref<string | null>(null);

watch(roles, (list) => {
    if (pendingSlug.value !== null) {
        const created = list.find((role) => role.slug === pendingSlug.value);

        if (created !== undefined) {
            pendingSlug.value = null;
            selectedRoleId.value = created.id;

            return;
        }
    }

    if (selectedRoleId.value !== null && list.some((role) => role.id === selectedRoleId.value)) {
        return;
    }

    selectedRoleId.value = list[0]?.id ?? null;
});

const enabledCount = computed(() => Object.values(form.permissions).filter(Boolean).length);
const totalCount = computed(() => allPermissionKeys.value.length);

function selectRole(role: WorkspaceRoleData) {
    selectedRoleId.value = role.id;
}

function toggleGroup(key: string) {
    collapsedGroups.value[key] = !collapsedGroups.value[key];
}

function toggleAll() {
    const anyOff = allPermissionKeys.value.some((key) => !form.permissions[key]);
    const next: Record<string, boolean> = {};

    allPermissionKeys.value.forEach((key) => (next[key] = anyOff));

    form.permissions = next;
}

function saveRole() {
    const role = selectedRole.value;

    if (role === null || !props.canManageRoles) {
        return;
    }

    form.put(workspaceRoute('workspace.roles.update', { role: role.id }), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Head title="Role Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Workspace"
                title="Role Management"
                description="Create custom roles and fine-tune what each member can do in your workspace."
            >
                <template #actions>
                    <Button v-if="canManageRoles" size="sm" class="gap-1.5" @click="isCreateWorkspaceRoleModalOpen = true">
                        <Plus class="size-3.5" />
                        New role
                    </Button>
                </template>
            </AppPageHeader>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_380px] lg:items-start">
                <div class="flex flex-col gap-5">
                    <div>
                        <p class="text-muted-foreground mb-2.5 text-[11px] font-medium tracking-[.06em] uppercase">System roles</p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div v-for="role in systemRoles" :key="role.value" class="bg-card rounded-xl border p-4 shadow-sm">
                                <div
                                    class="mb-2.5 flex size-9 items-center justify-center rounded-lg"
                                    :style="{ background: systemRoleStyles[role.value]?.iconBg ?? '#EEEDFE' }"
                                >
                                    <component
                                        :is="systemRoleStyles[role.value]?.icon ?? Shield"
                                        class="size-4"
                                        :style="{ color: systemRoleStyles[role.value]?.iconColor ?? '#534AB7' }"
                                    />
                                </div>
                                <p class="text-sm font-medium">{{ role.label }}</p>
                                <p class="text-muted-foreground mt-0.5 text-xs leading-relaxed">{{ role.description }}</p>
                                <div class="mt-3 flex items-center justify-between">
                                    <span class="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase">
                                        System
                                    </span>
                                    <span class="text-muted-foreground flex items-center gap-1 text-[11px] tabular-nums">
                                        <Users class="size-3" />
                                        {{ role.member_count }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="border-border h-px flex-1 border-t" />
                        <span class="text-muted-foreground text-[11px] tracking-[.04em] uppercase">Custom roles</span>
                        <div class="border-border h-px flex-1 border-t" />
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <button
                            v-for="role in roles"
                            :key="role.id"
                            type="button"
                            class="bg-card rounded-xl border p-4 text-left shadow-sm transition-all"
                            :class="selectedRoleId === role.id ? 'border-primary ring-primary/20 ring-2' : 'hover:border-foreground/20'"
                            @click="selectRole(role)"
                        >
                            <div class="bg-muted mb-2.5 flex size-9 items-center justify-center rounded-lg">
                                <component :is="customIconMap[role.slug] ?? Code" class="text-foreground/70 size-4" />
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
                                <span class="text-muted-foreground flex items-center gap-1 text-[11px] tabular-nums">
                                    <Users class="size-3" />
                                    {{ role.member_count ?? 0 }}
                                </span>
                            </div>
                        </button>

                        <button
                            v-if="canManageRoles"
                            type="button"
                            class="border-border text-muted-foreground hover:bg-muted flex min-h-[116px] flex-col items-center justify-center gap-2 rounded-xl border border-dashed transition-colors"
                            @click="isCreateWorkspaceRoleModalOpen = true"
                        >
                            <Plus class="size-5" />
                            <span class="text-sm font-medium">New role</span>
                        </button>
                    </div>

                    <div v-if="roles.length === 0 && !canManageRoles" class="text-muted-foreground text-sm">
                        No custom roles have been defined for this workspace yet.
                    </div>
                </div>

                <div class="bg-card overflow-hidden rounded-xl border shadow-sm">
                    <template v-if="selectedRole">
                        <div class="border-b p-4">
                            <div class="mb-3">
                                <label for="role-name" class="text-muted-foreground mb-1.5 block text-xs font-medium">Role name</label>
                                <input
                                    id="role-name"
                                    v-model="form.name"
                                    type="text"
                                    :disabled="!canManageRoles"
                                    class="border-input bg-background focus:ring-primary/30 w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-2 disabled:opacity-60"
                                />
                                <p v-if="form.errors.name" class="text-destructive mt-1.5 text-xs">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground mb-1.5 text-xs font-medium">Identifier</p>
                                <div class="border-input bg-muted flex overflow-hidden rounded-lg border text-sm">
                                    <span class="text-muted-foreground border-input border-r px-3 py-2 text-xs">workspace.</span>
                                    <span class="text-muted-foreground flex-1 px-3 py-2 text-sm">{{ selectedRole.slug }}</span>
                                </div>
                                <p class="text-muted-foreground mt-1.5 text-[11px]">The identifier is fixed once a role is created.</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-b px-4 py-3">
                            <div class="flex items-center gap-2 text-sm font-medium">
                                <Shield class="text-primary size-3.5" />
                                Permissions
                                <span class="bg-primary/10 text-primary rounded px-1.5 py-0.5 text-[10px] font-medium tabular-nums">
                                    {{ enabledCount }} / {{ totalCount }}
                                </span>
                            </div>
                            <button
                                v-if="canManageRoles"
                                type="button"
                                class="text-muted-foreground hover:text-foreground text-xs transition-colors"
                                @click="toggleAll"
                            >
                                Toggle all
                            </button>
                        </div>

                        <p v-if="form.errors.permissions" class="text-destructive border-b px-4 py-2 text-xs">{{ form.errors.permissions }}</p>

                        <div class="text-muted-foreground flex items-start gap-2 border-b px-4 py-2.5 text-[11px] leading-relaxed">
                            <Info class="mt-px size-3.5 shrink-0" />
                            <span>
                                Permissions are recorded against the role for team structure. Access is currently enforced by the system role (Owner /
                                Admin / Member) and by project membership.
                            </span>
                        </div>

                        <div>
                            <div v-for="group in permissionGroups" :key="group.key" class="border-b last:border-b-0">
                                <button
                                    type="button"
                                    class="bg-muted/50 flex w-full items-center justify-between px-4 py-2.5"
                                    @click="toggleGroup(group.key)"
                                >
                                    <span class="text-muted-foreground flex items-center gap-1.5 text-[11px] font-medium tracking-[.04em] uppercase">
                                        <component :is="group.icon" class="size-3.5" />
                                        {{ group.label }}
                                    </span>
                                    <component :is="collapsedGroups[group.key] ? ChevronDown : ChevronUp" class="text-muted-foreground size-3.5" />
                                </button>

                                <div v-if="!collapsedGroups[group.key]">
                                    <div
                                        v-for="permission in group.permissions"
                                        :key="permission.key"
                                        class="hover:bg-muted/30 flex items-center justify-between border-t px-4 py-2.5 transition-colors"
                                    >
                                        <div>
                                            <p class="text-sm">{{ permission.label }}</p>
                                            <p class="text-muted-foreground text-[11px]">{{ permission.hint }}</p>
                                        </div>

                                        <AppSwitch
                                            v-model="form.permissions[permission.key]"
                                            :disabled="!canManageRoles"
                                            :label="`${permission.label} for ${selectedRole.name}`"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="canManageRoles" class="bg-muted/30 flex items-center justify-between gap-3 border-t px-4 py-3">
                            <Button
                                variant="outline"
                                size="sm"
                                class="text-destructive border-destructive/40 gap-1.5"
                                @click="isDeleteDialogOpen = true"
                            >
                                <Trash class="size-3.5" />
                                Delete role
                            </Button>

                            <Button size="sm" :disabled="form.processing || !form.isDirty" @click="saveRole">
                                <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                                {{ form.processing ? 'Saving…' : 'Save changes' }}
                            </Button>
                        </div>
                    </template>

                    <div v-else class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center">
                        <Shield class="text-muted-foreground size-8 opacity-40" />
                        <p class="text-muted-foreground text-sm">
                            {{ canManageRoles ? 'Create a custom role to configure its permissions.' : 'No custom roles to configure.' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <CreateWorkspaceRoleModal v-model:open="isCreateWorkspaceRoleModalOpen" @created="(slug: string) => (pendingSlug = slug)" />

    <DeleteWorkspaceRoleDialog v-model:open="isDeleteDialogOpen" :role="selectedRole" />
</template>
