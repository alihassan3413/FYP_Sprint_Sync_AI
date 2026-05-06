<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CreditCard, Key, LoaderCircle, Mail, Send, User } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
  seats?: { used: number; total: number };
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm({
  email: '',
  role: 'member',
});

const submit = () => {
    console.log(form);

  form.post(workspaceRoute('workspace.invitations.store'), {
    onSuccess: () => form.reset(),
  });
};

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Team', href: workspaceRoute('workspace.teams.index') },
  { title: 'Invite member', href: workspaceRoute('workspace.invitations.create') },
];

const roleOptions = [
  {
    value: 'member',
    label: 'Member',
    description: 'Can create and complete tasks, comment, and view sprints.',
    icon: User,
    badge: 'Default',
  },
  {
    value: 'admin',
    label: 'Admin',
    description: 'Everything members can do, plus manage projects, sprints, and integrations.',
    icon: Key,
  },
];
</script>

<template>
  <Head title="Invite member" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="mx-auto flex w-full max-w-xl flex-col gap-6 p-4 md:p-6 lg:p-8">

      <AppPageHeader
        eyebrow="Workspace · Team"
        title="Invite a teammate"
        description="They'll get an email to join your workspace on SprintSync."
      />

      <form
        @submit.prevent="submit"
        class="flex flex-col rounded-xl border bg-card shadow-sm"
      >
        <div class="flex flex-col gap-5 p-5 sm:p-6">

          <AppFormInput
            v-model="form.email"
            id="email"
            label="Email address"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="rajab@example.com"
            hint="They'll receive a one-click invite — no password required from you."
            :icon="Mail"
          />

          <div class="grid gap-2">
            <Label class="text-[13px] font-medium">Role</Label>

            <AppRadioCard
              v-model="form.role"
              :options="roleOptions"
              accent="blue"
            />

            <InputError :message="form.errors.role" />
          </div>
        </div>

        <div
          class="flex flex-col-reverse items-stretch gap-3 border-t bg-muted/20 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
          <div
            v-if="seats"
            class="flex items-center gap-1.5 text-[11.5px] text-muted-foreground"
          >
            <CreditCard class="size-3.5" />
            <span class="tabular-nums">
              {{ seats.used }} of {{ seats.total }} seats used
            </span>
          </div>
          <div v-else />

          <div class="flex items-center justify-end gap-2">
            <Button as-child variant="ghost" size="sm" type="button" tabindex="3">
              <Link :href="workspaceRoute('workspace.teams.index')">
                Cancel
              </Link>
            </Button>

            <Button
              type="submit"
              size="sm"
              tabindex="2"
              :disabled="form.processing"
              class="gap-1.5"
            >
              <LoaderCircle v-if="form.processing" class="size-3.5 animate-spin" />
              <Send v-else class="size-3.5" />
              Send invitation
            </Button>
          </div>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
