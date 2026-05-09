<script setup lang="ts">
import AuthBase from '@/layouts/AuthLayout.vue';
import { CheckCircle2, Eye, EyeOff, LoaderCircle } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Welcome back" description="Sign in to continue to Sprint Sync">
        <Head title="Log in" />

        <div class="space-y-5">
            <div
                v-if="status"
                class="flex items-center gap-2 border-[1.5px] border-black bg-primary px-4 py-2.5 text-sm font-semibold text-black"
            >
                <CheckCircle2 class="h-4 w-4 shrink-0" />
                {{ status }}
            </div>

            <form @submit.prevent="submit" class="space-y-3.5">
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
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        placeholder="you@example.com"
                        class="h-9 rounded-[6px] border-[1.5px] border-black bg-white focus-visible:border-2 focus-visible:border-black focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <Label
                            for="password"
                            class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                        >
                            Password
                        </Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-[10px] font-bold uppercase tracking-[0.18em]"
                            :tabindex=5
                        >
                            Forgot?
                        </TextLink>
                    </div>
                    <div class="relative">
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            tabindex="2"
                            autocomplete="current-password"
                            v-model="form.password"
                            placeholder="Your password"
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

                <div class="flex items-center gap-2.5 pt-0.5">
                    <Checkbox
                        id="remember"
                        v-model:checked="form.remember"
                        tabindex="3"
                        class="border-[1.5px] border-black"
                    />
                    <Label for="remember" class="cursor-pointer text-sm font-normal text-zinc-700">
                        Remember me for 30 days
                    </Label>
                </div>

                <Button
                    type="submit"
                    tabindex="4"
                    :disabled="form.processing"
                    class="w-full rounded-[6px] border-[1.5px] border-black bg-black font-bold text-white shadow-[4px_4px_0_#000] transition-all duration-100 hover:translate-x-[2px] hover:translate-y-[2px] hover:bg-primary hover:text-black hover:shadow-[2px_2px_0_#000] disabled:opacity-60"
                >
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Signing in...' : 'Sign in' }}
                </Button>
            </form>

            <p class="text-sm text-zinc-500">
                Don't have an account?
                <Link
                    :href="route('register')"
                    :tabindex="5"
                    class="relative isolate ml-0.5 inline-block px-1.5 py-0.5 font-semibold cursor-pointer text-black no-underline before:absolute before:inset-0 before:-z-10 before:-skew-x-6 before:bg-primary before:opacity-0 before:transition-opacity hover:before:opacity-100"
                >
                    Create one free
                </Link>
            </p>
        </div>
    </AuthBase>
</template>
