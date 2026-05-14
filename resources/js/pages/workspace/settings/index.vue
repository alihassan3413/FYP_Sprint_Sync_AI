<script setup lang="ts">
import { type BreadcrumbItem } from '@/types';

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: workspaceRoute('dashboard'),
    }
];
</script>

<template>
  <Head title="Settings" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
      <!-- Page header -->
      <AppPageHeader
        eyebrow="Workspace"
        title="Settings"
        description="Manage your workspace details, members, roles, and access controls."
      />
    </div>
  </AppLayout>
</template>

<style scoped>
div {
  color: blue;
  font-size: 18px;
}
</style>
