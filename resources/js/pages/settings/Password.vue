<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, KeyRound, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import SavedIndicator from '@/components/settings/SavedIndicator.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import AppPasswordInput from '@/components/ui/AppPasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: '/settings/password',
    },
];

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const checks = computed(() => [
    { label: 'At least 8 characters', met: form.password.length >= 8, required: true },
    { label: 'Upper and lower case', met: /[a-z]/.test(form.password) && /[A-Z]/.test(form.password), required: false },
    { label: 'A number', met: /\d/.test(form.password), required: false },
    { label: 'A symbol', met: /[^A-Za-z0-9]/.test(form.password), required: false },
]);

const passwordsMatch = computed(() => form.password.length > 0 && form.password === form.password_confirmation);

const canSubmit = computed(() => !form.processing && form.current_password.length > 0 && form.password.length >= 8 && passwordsMatch.value);

function focusField(id: string) {
    document.getElementById(id)?.focus();
}

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors: Record<string, string>) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                focusField('password');
            }

            if (errors.current_password) {
                form.reset('current_password');
                focusField('current_password');
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Password settings" />

        <SettingsLayout>
            <SettingsSection :icon="KeyRound" title="Change password" description="Use a long, unique password that you don't reuse anywhere else.">
                <form id="password-form" class="grid gap-5" @submit.prevent="updatePassword">
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="current_password">Current password</Label>
                        <AppPasswordInput
                            id="current_password"
                            v-model="form.current_password"
                            autocomplete="current-password"
                            placeholder="Enter your current password"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>

                    <div class="bg-border/70 h-px" />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="password">New password</Label>
                            <AppPasswordInput
                                id="password"
                                v-model="form.password"
                                autocomplete="new-password"
                                placeholder="Create a new password"
                                show-strength
                            />
                            <InputError :message="form.errors.password" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password_confirmation">Confirm new password</Label>
                            <AppPasswordInput
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                autocomplete="new-password"
                                placeholder="Repeat the new password"
                            />
                            <p v-if="form.password_confirmation && !passwordsMatch" class="text-muted-foreground text-[13px]">
                                Both passwords need to match.
                            </p>
                            <InputError :message="form.errors.password_confirmation" />
                        </div>
                    </div>

                    <div class="border-border/70 bg-muted/25 rounded-lg border p-4">
                        <p class="text-foreground mb-3 flex items-center gap-2 text-[13px] font-medium">
                            <ShieldCheck class="text-muted-foreground size-4" />
                            Make it hard to guess
                        </p>

                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li
                                v-for="check in checks"
                                :key="check.label"
                                :class="[
                                    'flex items-center gap-2 text-[13px] transition-colors',
                                    check.met ? 'text-foreground' : 'text-muted-foreground',
                                ]"
                            >
                                <span
                                    :class="[
                                        'flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                                        check.met
                                            ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                            : 'border-border bg-background text-transparent',
                                    ]"
                                >
                                    <Check class="size-3" />
                                </span>
                                {{ check.label }}
                                <span v-if="!check.required" class="text-muted-foreground/70 text-[11px]">optional</span>
                            </li>
                        </ul>
                    </div>
                </form>

                <template #footer>
                    <p class="text-muted-foreground text-[13px]">You'll stay signed in on this device.</p>

                    <div class="flex items-center gap-3">
                        <SavedIndicator :show="form.recentlySuccessful" label="Password updated" />

                        <Button form="password-form" type="submit" size="sm" :disabled="!canSubmit">
                            {{ form.processing ? 'Updating…' : 'Update password' }}
                        </Button>
                    </div>
                </template>
            </SettingsSection>
        </SettingsLayout>
    </AppLayout>
</template>
