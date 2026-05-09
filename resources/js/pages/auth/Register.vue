<script setup lang="ts">
import AuthBase from '@/layouts/AuthLayout.vue';
import { Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    workspace_name: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation', 'workspace_name'),
    });
};

const hasUserManuallyEditedWorkspaceName = ref(false);

function generateWorkspaceName(name: string): string {
    return name.toUpperCase() + "'s Workspace";
}

function handleWorkspaceNameInput() {
    hasUserManuallyEditedWorkspaceName.value = true;
}

watch(
    () => form.name,
    (newName) => {
        if (!hasUserManuallyEditedWorkspaceName.value) {
            form.workspace_name = generateWorkspaceName(newName);
        }
    },
);
</script>

<template>
    <AuthBase title="Create your account" description="Start your free trial today">
        <Head title="Register" />

        <form @submit.prevent="submit" class="space-y-3.5">
            <div class="space-y-1.5">
                <Label
                    for="name"
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                >
                    Full name
                </Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    tabindex="1"
                    autocomplete="name"
                    v-model="form.name"
                    placeholder="John Doe"
                    class="h-9 rounded-[6px] border-[1.5px] border-black bg-white focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="space-y-1.5">
                <Label
                    for="email"
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                >
                    Email address
                </Label>
                <Input
                    id="email"
                    type="email"
                    required
                    tabindex="2"
                    autocomplete="email"
                    v-model="form.email"
                    placeholder="you@example.com"
                    class="h-9 rounded-[6px] border-[1.5px] border-black bg-white focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="space-y-1.5">
                <Label
                    for="password"
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                >
                    Password
                </Label>
                <div class="relative">
                    <Input
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password"
                        placeholder="Create a password"
                        class="h-9 rounded-[6px] border-[1.5px] border-black bg-white pr-10 focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                    />
                    <button
                        type="button"
                        tabindex="-1"
                        class="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-zinc-400 transition-colors hover:text-black"
                        @click="showPassword = !showPassword"
                    >
                        <EyeOff v-if="showPassword" class="h-4 w-4" />
                        <Eye v-else class="h-4 w-4" />
                    </button>
                </div>
                <InputError :message="form.errors.password" />
            </div>

            <div class="space-y-1.5">
                <Label
                    for="password_confirmation"
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                >
                    Confirm password
                </Label>
                <div class="relative">
                    <Input
                        id="password_confirmation"
                        :type="showPasswordConfirmation ? 'text' : 'password'"
                        required
                        tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        placeholder="Repeat your password"
                        class="h-9 rounded-[6px] border-[1.5px] border-black bg-white pr-10 focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                    />
                    <button
                        type="button"
                        tabindex="-1"
                        class="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-zinc-400 transition-colors hover:text-black"
                        @click="showPasswordConfirmation = !showPasswordConfirmation"
                    >
                        <EyeOff v-if="showPasswordConfirmation" class="h-4 w-4" />
                        <Eye v-else class="h-4 w-4" />
                    </button>
                </div>
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div class="space-y-1.5">
                <Label
                    for="workspace_name"
                    class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                >
                    Workspace name
                </Label>
                <Input
                    id="workspace_name"
                    type="text"
                    tabindex="5"
                    v-model="form.workspace_name"
                    @input="handleWorkspaceNameInput"
                    placeholder="Your workspace name"
                    class="h-9 rounded-[6px] border-[1.5px] border-black bg-white focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                />
                <InputError :message="form.errors.workspace_name" />
            </div>

            <Button
                type="submit"
                tabindex="6"
                :disabled="form.processing"
                class="w-full rounded-[6px] border-[1.5px] border-black bg-black font-bold text-white shadow-[4px_4px_0_#000] transition-all duration-100 hover:translate-x-[2px] hover:translate-y-[2px] hover:bg-primary hover:text-black hover:shadow-[2px_2px_0_#000] disabled:opacity-60"
            >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                {{ form.processing ? 'Creating account...' : 'Create account' }}
            </Button>
        </form>

        <p class="mt-5 text-sm text-zinc-500">
            Already have an account?
            <Link
                :href="route('login')"
                tabindex="7"
                class="relative isolate cursor-pointer ml-0.5 inline-block px-1.5 py-0.5 font-semibold text-black no-underline before:absolute before:inset-0 before:-z-10 before:-skew-x-6 before:bg-[#D4FF4A] before:opacity-0 before:transition-opacity hover:before:opacity-100"
            >
                Sign in
            </Link>
        </p>
    </AuthBase>
</template>
