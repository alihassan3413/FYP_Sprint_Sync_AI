<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { BadgeCheck, Globe, MailWarning, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

import AvatarSettings from '@/components/AvatarSettings.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import SavedIndicator from '@/components/settings/SavedIndicator.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const detectedTimezone = detectTimezone();
const timezoneChoices = timezoneOptions();

const form = useForm({
    name: user.name,
    email: user.email,
    timezone: user.timezone ?? detectedTimezone,
});

const selectedOffset = computed(() => (form.timezone ? timezoneOffsetLabel(form.timezone) : null));

const localPreview = computed(() =>
    form.timezone
        ? new Date().toLocaleString(undefined, {
              timeZone: form.timezone,
              weekday: 'short',
              hour: 'numeric',
              minute: '2-digit',
          })
        : null,
);

const canUseDetected = computed(() => form.timezone !== detectedTimezone);

const memberSince = computed(() => {
    const joined = new Date(user.created_at);

    if (Number.isNaN(joined.getTime())) {
        return null;
    }

    return joined.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Profile settings" />

        <SettingsLayout>
            <AvatarSettings />

            <SettingsSection
                :icon="UserRound"
                title="Personal information"
                description="The name and address teammates see, and where account mail is sent."
            >
                <template #aside>
                    <span
                        v-if="user.email_verified_at"
                        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-400"
                    >
                        <BadgeCheck class="size-3.5" />
                        Verified
                    </span>
                </template>

                <form id="profile-form" class="grid gap-5 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="name">Full name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            autocomplete="name"
                            placeholder="Your name"
                            :aria-invalid="!!form.errors.name"
                            :class="form.errors.name && 'border-destructive focus-visible:ring-destructive'"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            placeholder="you@company.com"
                            :aria-invalid="!!form.errors.email"
                            :class="form.errors.email && 'border-destructive focus-visible:ring-destructive'"
                        />
                        <InputError :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2 sm:col-span-2">
                        <div class="flex items-center justify-between gap-3">
                            <Label for="timezone">Timezone</Label>

                            <button
                                v-if="canUseDetected"
                                type="button"
                                class="text-muted-foreground hover:text-foreground text-[11px] underline underline-offset-2 transition-colors"
                                @click="form.timezone = detectedTimezone"
                            >
                                Use detected ({{ detectedTimezone }})
                            </button>
                        </div>

                        <select
                            id="timezone"
                            v-model="form.timezone"
                            class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                            :aria-invalid="!!form.errors.timezone"
                            :class="form.errors.timezone && 'border-destructive focus-visible:ring-destructive'"
                        >
                            <option v-for="option in timezoneChoices" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>

                        <p v-if="localPreview" class="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <Globe class="size-3.5 shrink-0" />
                            <span>
                                Meeting times are entered and displayed in this zone. It is
                                {{ localPreview }} there ({{ selectedOffset }}).
                            </span>
                        </p>

                        <InputError :message="form.errors.timezone" />
                    </div>

                    <div
                        v-if="mustVerifyEmail && !user.email_verified_at"
                        class="flex flex-col gap-2 rounded-lg border border-amber-500/25 bg-amber-500/5 p-3.5 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="flex items-start gap-2.5 text-[13px] text-amber-800 dark:text-amber-300">
                            <MailWarning class="mt-px size-4 shrink-0" />
                            <span v-if="status === 'verification-link-sent'"> A fresh verification link is on its way to your inbox. </span>
                            <span v-else> Your email address hasn't been verified yet. </span>
                        </p>

                        <Button
                            v-if="status !== 'verification-link-sent'"
                            as-child
                            variant="outline"
                            size="sm"
                            class="shrink-0 self-start border-amber-500/30 bg-transparent hover:bg-amber-500/10 sm:self-auto"
                        >
                            <Link :href="route('verification.send')" method="post" as="button">Resend link</Link>
                        </Button>
                    </div>
                </form>

                <template #footer>
                    <p class="text-muted-foreground text-[13px]">
                        <span v-if="memberSince">Member since {{ memberSince }}</span>
                    </p>

                    <div class="flex items-center gap-3">
                        <SavedIndicator :show="form.recentlySuccessful" />

                        <Button form="profile-form" type="submit" size="sm" :disabled="form.processing || !form.isDirty">
                            {{ form.processing ? 'Saving…' : 'Save changes' }}
                        </Button>
                    </div>
                </template>
            </SettingsSection>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
