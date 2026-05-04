<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    token: string;
    invitation: {
        email: string;
        role: string;
        workspace: {
            name: string;
        };
    };
}>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('workspace.invitations.accept.store', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Accept invitation" description="Create your account to join the workspace">
        <Head title="Accept Invitation" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="rounded-lg border bg-muted/40 p-4 text-sm">
                <p class="text-muted-foreground">You have been invited to join</p>

                <p class="mt-1 font-semibold">
                    {{ invitation.workspace.name }}
                </p>

                <p class="mt-2 text-muted-foreground">
                    Email:
                    <span class="font-medium text-foreground">
                        {{ invitation.email }}
                    </span>
                </p>

                <p class="mt-1 text-muted-foreground">
                    Role:
                    <span class="font-medium capitalize text-foreground">
                        {{ invitation.role }}
                    </span>
                </p>
            </div>

            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>

                    <Input
                        id="name"
                        type="text"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="name"
                        v-model="form.name"
                        placeholder="Full name"
                    />

                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>

                    <Input
                        id="password"
                        type="password"
                        required
                        tabindex="2"
                        autocomplete="new-password"
                        v-model="form.password"
                        placeholder="Password"
                    />

                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>

                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        placeholder="Confirm password"
                    />

                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Join workspace
                </Button>
            </div>
        </form>
    </AuthBase>
</template>
