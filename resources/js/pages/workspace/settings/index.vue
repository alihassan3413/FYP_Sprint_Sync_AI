<script setup lang="ts">
import { type BreadcrumbItem } from '@/types';
import { Link } from '@inertiajs/vue3';

import {
    ShieldCheck,
    Users,
    Mail,
    CreditCard,
    Lock,
    Bell,
    Building2,
    AlertTriangle,
    ArrowRight,
    Info,
} from 'lucide-vue-next';

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: workspaceRoute('dashboard'),
    },
    {
        title: 'Settings',
        href: ''
    }
];

type BadgeVariant = 'available' | 'soon' | 'later';

interface SettingsCard {
    key: string;
    icon: typeof ShieldCheck;
    title: string;
    description: string;
    badge: BadgeVariant;
    badgeLabel: string;
    action?: {
        label: string;
        href: string;
    };
    highlighted?: boolean;
    danger?: boolean;
}

const settings: SettingsCard[] = [
    {
        key: 'roles',
        icon: ShieldCheck,
        title: 'Role Management',
        description: 'Define custom workspace roles and assign fine-grained permissions to control what members can see and do.',
        badge: 'available',
        badgeLabel: 'Available',
        action: {
            label: 'Manage roles',
            href: workspaceRoute('workspace.roles.index'),
        },
        highlighted: true,
    },
    {
        key: 'profile',
        icon: Building2,
        title: 'Workspace Profile',
        description: 'Update your workspace name, logo, and general information visible to all members.',
        badge: 'soon',
        badgeLabel: 'Soon',
    },
    {
        key: 'members',
        icon: Users,
        title: 'Members',
        description: 'View and manage all current members of your workspace, update their roles and status.',
        badge: 'soon',
        badgeLabel: 'Soon',
    },
    {
        key: 'invitations',
        icon: Mail,
        title: 'Invitations',
        description: 'Send and manage pending invitations. Control who can join and under what role.',
        badge: 'soon',
        badgeLabel: 'Soon',
    },
    {
        key: 'billing',
        icon: CreditCard,
        title: 'Billing',
        description: 'Manage your subscription plan, view invoices, and update payment methods.',
        badge: 'later',
        badgeLabel: 'Coming later',
    },
    {
        key: 'security',
        icon: Lock,
        title: 'Security',
        description: 'Configure SSO, enforce two-factor authentication, and review active sessions.',
        badge: 'later',
        badgeLabel: 'Coming later',
    },
    {
        key: 'notifications',
        icon: Bell,
        title: 'Notifications',
        description: 'Set workspace-level notification defaults and alert preferences for your team.',
        badge: 'later',
        badgeLabel: 'Coming later',
    },
    {
        key: 'danger',
        icon: AlertTriangle,
        title: 'Danger Zone',
        description: 'Permanently delete this workspace and all associated data. This action cannot be undone.',
        badge: 'later',
        badgeLabel: 'Coming later',
        danger: true,
    },
];

const badgeClasses: Record<BadgeVariant, string> = {
    available: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
    soon: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
    later: 'bg-zinc-100 text-zinc-500 ring-1 ring-zinc-200',
};
</script>

<template>
    <Head title="Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">

            <!-- Page Header -->
            <AppPageHeader
                eyebrow="Workspace"
                title="Settings"
                description="Manage your workspace details, members, roles, and access controls."
            />

            <!-- Settings Grid -->
            <section aria-label="Settings categories">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <template v-for="card in settings" :key="card.key">
                        <div
                            :class="[
                                'group relative flex flex-col rounded-xl border bg-white p-5 transition-all duration-150',
                                card.highlighted
                                    ? 'border-zinc-900 shadow-sm ring-1 ring-zinc-900/10'
                                    : card.danger
                                    ? 'border-red-200 hover:border-red-300 hover:shadow-sm'
                                    : 'border-zinc-200 hover:border-zinc-300 hover:shadow-sm',
                            ]"
                        >
                            <!-- Top row: icon + badge -->
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <div
                                    :class="[
                                        'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                                        card.highlighted
                                            ? 'bg-zinc-900 text-white'
                                            : card.danger
                                            ? 'bg-red-50 text-red-500'
                                            : 'bg-zinc-100 text-zinc-500',
                                    ]"
                                >
                                    <component :is="card.icon" class="h-4 w-4" :stroke-width="1.75" />
                                </div>

                                <span
                                    :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium leading-4',
                                        badgeClasses[card.badge],
                                    ]"
                                >
                                    {{ card.badgeLabel }}
                                </span>
                            </div>

                            <!-- Title + description -->
                            <div class="flex flex-col flex-1 gap-1 mb-5">
                                <h3
                                    :class="[
                                        'text-sm font-semibold leading-snug',
                                        card.danger ? 'text-red-600' : 'text-zinc-900',
                                    ]"
                                >
                                    {{ card.title }}
                                </h3>
                                <p class="text-sm text-zinc-500 leading-relaxed">
                                    {{ card.description }}
                                </p>
                            </div>

                            <!-- Action -->
                            <div v-if="card.action" class="mt-auto">
                                <Link
                                    :href="card.action.href"
                                    :class="[
                                        'inline-flex items-center gap-1.5 text-sm font-medium transition-colors duration-100',
                                        card.highlighted
                                            ? 'text-zinc-900 hover:text-zinc-600'
                                            : 'text-zinc-600 hover:text-zinc-900',
                                    ]"
                                >
                                    {{ card.action.label }}
                                    <ArrowRight class="h-3.5 w-3.5 transition-transform duration-150 group-hover:translate-x-0.5" :stroke-width="2" />
                                </Link>
                            </div>

                            <!-- Highlighted accent bar -->
                            <div
                                v-if="card.highlighted"
                                class="absolute inset-x-0 top-0 h-px rounded-t-xl bg-linear-to-r from-zinc-400 via-zinc-700 to-zinc-400"
                            />
                        </div>
                    </template>
                </div>
            </section>

            <!-- Workspace Access Model -->
            <section
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-6"
                aria-label="Workspace access model explainer"
            >
                <div class="flex items-start gap-3 mb-5">
                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white border border-zinc-200 text-zinc-400 shadow-xs">
                        <Info class="h-3.5 w-3.5" :stroke-width="2" />
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-800">Workspace access model</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 leading-relaxed">
                            This workspace uses a two-layer permission model to balance structure with flexibility.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-zinc-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-zinc-400 mb-2">System Roles</p>
                        <p class="text-sm font-medium text-zinc-800 mb-1">Owner · Admin · Member</p>
                        <p class="text-xs text-zinc-500 leading-relaxed">
                            Built-in roles that control platform-level access — who can manage billing, invite members, or administer the workspace itself.
                        </p>
                    </div>
                    <div class="rounded-lg border border-zinc-200 bg-white p-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-zinc-400 mb-2">Workspace Roles</p>
                        <p class="text-sm font-medium text-zinc-800 mb-1">Frontend Dev · QA · Sales · Designer…</p>
                        <p class="text-xs text-zinc-500 leading-relaxed">
                            Custom roles you define to reflect your team structure. Assign granular permissions that match how your team actually works.
                        </p>
                    </div>
                </div>
            </section>

        </div>
    </AppLayout>
</template>
