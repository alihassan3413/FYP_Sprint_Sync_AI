<script setup lang="ts">
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
  ArrowRight,
  LoaderCircle,
  Mail,
  ShieldCheck,
  Sparkles,
  User,
  Users,
} from 'lucide-vue-next';

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
  <AuthBase>
    <form @submit.prevent="submit" class="flex flex-col gap-6">
      <div class="text-center">

        <h1 class="text-2xl font-semibold tracking-tight">
          Join {{ invitation.workspace.name }}
        </h1>

        <p class="mt-2 text-sm text-muted-foreground">
          You have been invited to collaborate on SprintSync.
        </p>
      </div>

      <div class="rounded-xl border bg-card p-4 shadow-sm">
        <div class="flex items-start gap-3">
          <div
            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
          >
            <Users class="size-5" />
          </div>

          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-foreground">
              {{ invitation.workspace.name }}
            </p>

            <div class="mt-2 grid gap-2 text-xs text-muted-foreground">
              <div class="flex items-center gap-2">
                <Mail class="size-3.5" />
                <span class="truncate">{{ invitation.email }}</span>
              </div>

              <div class="flex items-center gap-2">
                <ShieldCheck class="size-3.5" />
                <span>
                  Joining as
                  <span class="font-medium capitalize text-foreground">
                    {{ invitation.role }}
                  </span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid gap-5">
        <div class="grid gap-2">
          <Label for="name">Full name</Label>

          <div class="relative">
            <User
              class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />

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
          <Label for="password">Password</Label>

          <AppPasswordInput
            id="password"
            v-model="form.password"
            required
            tabindex="2"
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
            tabindex="3"
            autocomplete="new-password"
            placeholder="Confirm your password"
          />

          <InputError :message="form.errors.password_confirmation" />
        </div>

        <Button
          type="submit"
          class="mt-1 w-full gap-2"
          tabindex="4"
          :disabled="form.processing"
        >
          <LoaderCircle
            v-if="form.processing"
            class="size-4 animate-spin"
          />
          <ArrowRight v-else class="size-4" />

          Join workspace
        </Button>
      </div>
    </form>
  </AuthBase>
</template>
