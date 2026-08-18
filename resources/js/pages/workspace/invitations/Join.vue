<script setup lang="ts">
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, LoaderCircle, Mail, ShieldCheck, User, Users } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        token: string;
        requiresRegistration?: boolean;
        workspace: { name: string };
        expiresAt: string;
    }>(),
    { requiresRegistration: true },
);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const expiresLabel = computed(() => new Date(props.expiresAt).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));

const submit = () => {
    form.post(route('workspace.join.store', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Join workspace" />

    <AuthBase>
        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="text-center">
                <h1 class="text-2xl font-semibold tracking-tight">Join {{ workspace.name }}</h1>
                <p class="text-muted-foreground mt-2 text-sm">You opened a shared invite link for this workspace.</p>
            </div>

            <div class="bg-card rounded-xl border p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="bg-muted text-muted-foreground flex size-10 shrink-0 items-center justify-center rounded-lg">
                        <Users class="size-5" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="text-foreground truncate text-sm font-medium">{{ workspace.name }}</p>

                        <div class="text-muted-foreground mt-2 grid gap-2 text-xs">
                            <div class="flex items-center gap-2">
                                <ShieldCheck class="size-3.5" />
                                <span>Joining as <span class="text-foreground font-medium">Member</span></span>
                            </div>

                            <div class="flex items-center gap-2">
                                <CalendarClock class="size-3.5" />
                                <span>Link valid until {{ expiresLabel }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-5">
                <template v-if="requiresRegistration">
                    <div class="grid gap-2">
                        <Label for="name">Full name</Label>

                        <div class="relative">
                            <User class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                id="name"
                                v-model="form.name"
                                type="text"
                                required
                                autofocus
                                tabindex="1"
                                autocomplete="name"
                                placeholder="Enter your full name"
                                class="pl-9"
                            />
                        </div>

                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>

                        <div class="relative">
                            <Mail class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                required
                                tabindex="2"
                                autocomplete="email"
                                placeholder="you@company.com"
                                class="pl-9"
                            />
                        </div>

                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Password</Label>

                        <AppPasswordInput
                            id="password"
                            v-model="form.password"
                            required
                            tabindex="3"
                            autocomplete="new-password"
                            placeholder="Create a password"
                            show-strength
                        />

                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">Confirm password</Label>

                        <AppPasswordInput
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            required
                            tabindex="4"
                            autocomplete="new-password"
                            placeholder="Confirm your password"
                        />

                        <InputError :message="form.errors.password_confirmation" />
                    </div>
                </template>

                <Button type="submit" class="mt-1 w-full gap-2" tabindex="5" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <ArrowRight v-else class="size-4" />
                    Join workspace
                </Button>
            </div>
        </form>
    </AuthBase>
</template>
