<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    status: string;
};

defineProps<{
    members?: Member[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Teams',
        href: '/teams',
    },
];
</script>

<template>
    <Head title="Teams" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Teams</h1>
                    <p class="text-sm text-muted-foreground">
                        Manage your workspace members and invite new users.
                    </p>
                </div>

                <Button as-child>
                    <Link :href="route('workspace.invitations.create')">
                        Add Member
                    </Link>
                </Button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-6 py-3 font-medium text-muted-foreground">Name</th>
                                <th class="px-6 py-3 font-medium text-muted-foreground">Email</th>
                                <th class="px-6 py-3 font-medium text-muted-foreground">Role</th>
                                <th class="px-6 py-3 font-medium text-muted-foreground">Status</th>
                                <th class="px-6 py-3 text-right font-medium text-muted-foreground">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="member in members"
                                :key="member.id"
                                class="border-b last:border-b-0"
                            >
                                <td class="px-6 py-4 font-medium">
                                    {{ member.name }}
                                </td>

                                <td class="px-6 py-4 text-muted-foreground">
                                    {{ member.email }}
                                </td>

                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full bg-muted px-2.5 py-1 text-xs font-medium capitalize text-muted-foreground">
                                        {{ member.role }}
                                    </span>
                                </td>

                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                                        :class="
                                            member.status === 'active'
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-muted text-muted-foreground'
                                        "
                                    >
                                        {{ member.status }}
                                    </span>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <Button variant="ghost" size="sm">
                                        Manage
                                    </Button>
                                </td>
                            </tr>

                            <tr v-if="!members || members.length === 0">
                                <td colspan="5" class="px-6 py-10 text-center text-muted-foreground">
                                    No team members found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
