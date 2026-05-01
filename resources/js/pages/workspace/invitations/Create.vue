<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const form = useForm({
    email: '',
    role: 'member',
});

const submit = () => {
    form.post(route('workspace.invitations.store'), {
        onSuccess: () => form.reset(),
    });
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Teams',
        href: '/teams',
    },
    {
        title: 'Invite Member',
        href: '/teams/invitations/create',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Invite Member" />

        <div class="mx-auto flex w-full max-w-xl flex-col gap-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Invite Team Member</h1>
                <p class="text-sm text-muted-foreground">
                    Send an invitation to join your current workspace.
                </p>
            </div>

            <form @submit.prevent="submit" class="flex flex-col gap-6 rounded-xl border bg-card p-6 shadow-sm">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>

                        <Input
                            id="email"
                            type="email"
                            required
                            autofocus
                            tabindex="1"
                            autocomplete="email"
                            v-model="form.email"
                            placeholder="rajab@example.com"
                        />

                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Role</Label>

                        <select
                            id="role"
                            v-model="form.role"
                            tabindex="2"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                        </select>

                        <InputError :message="form.errors.role" />
                    </div>

                    <Button type="submit" class="mt-2 w-full" tabindex="3" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Send invitation
                    </Button>
                </div>

                <div class="text-center text-sm text-muted-foreground">
                    <Link :href="route('teams.index')" class="underline underline-offset-4">
                        Back to team members
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
